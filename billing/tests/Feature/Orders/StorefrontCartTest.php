<?php

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Modules\Orders\Enums\OrderItemStatus;
use App\Modules\Orders\Models\Cart;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\Promotion;
use App\Modules\Orders\Services\CartManager;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\Orders\OrdersFakes;

beforeEach(function () {
    Currency::factory()->create();
    $this->fakes = OrdersFakes::bind();
    $this->fakes->catalog->addProduct(1, 'Starter Hosting', ['monthly' => [1000, 500], 'annually' => [10000, 0]]);
});

describe('store pages', function () {
    it('lists product groups with cycle prices', function () {
        $this->get(route('store.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('public/store/index')
                ->where('currency', 'USD')
                ->where('groups.0.products.0.name', 'Starter Hosting')
                ->where('groups.0.products.0.pricing.0', ['cycle' => 'monthly', 'recurring' => 1000, 'setup' => 500])
                ->where('cart_count', 0));
    });

    it('renders the configure page with every available cycle', function () {
        $this->get(route('store.products.show', 1))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('public/store/configure')
                ->where('product.name', 'Starter Hosting')
                ->has('cycles', 2)
                ->where('cycles.1', ['cycle' => 'annually', 'recurring' => 10000, 'setup' => 0]));
    });

    it('returns 404 for an unknown product', function () {
        $this->get(route('store.products.show', 99))->assertNotFound();
    });

    it('previews the configured price from the server', function () {
        $this->postJson(route('store.products.quote', 1), ['cycle' => 'annually'])
            ->assertOk()
            ->assertJsonPath('item.lines.0.recurring', 10000)
            ->assertJsonPath('subtotal', 10000);
    });

    it('prices a searched domain and suggestions', function () {
        $this->get(route('store.domains', ['domain' => 'acme.com']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('public/store/domains')
                ->where('results.0', ['domain' => 'acme.com', 'tld' => '.com', 'register' => 1200, 'transfer' => 1000, 'renew' => 1400, 'register_terms' => [1, 2, 3, 5, 10], 'transfer_terms' => [1, 2, 3, 5, 10]])
                ->where('results.1.domain', 'acme.net'));
    });
});

describe('cart', function () {
    it('adds a configured product to a guest cart', function () {
        $this->post(route('store.cart.products.store', 1), ['cycle' => 'monthly'])
            ->assertRedirect();

        $cart = Cart::query()->sole();
        expect($cart->client_id)->toBeNull()
            ->and($cart->cartItems()[0]->productId)->toBe(1)
            ->and(session(CartManager::SESSION_TOKEN))->toBe($cart->token);
    });

    it('re-quotes the cart on every read', function () {
        $this->post(route('store.cart.products.store', 1), ['cycle' => 'monthly']);
        $this->fakes->catalog->setPrice(1, 'monthly', 1200, 0);

        $this->get(route('store.cart'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('public/cart/index')
                ->where('cart.total', 1200)
                ->where('cart.items.0.lines.0.recurring', 1200));
    });

    it('shows tax and recurring totals in the cart', function () {
        $this->fakes->billing->taxRate = 10;
        $this->post(route('store.cart.products.store', 1), ['cycle' => 'monthly']);

        $this->get(route('store.cart'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('cart.subtotal', 1500)
                ->where('cart.tax_total', 150)
                ->where('cart.total', 1650)
                ->where('cart.recurring', [['cycle' => 'monthly', 'amount' => 1000]]));
    });

    it('validates the billing cycle against the catalog', function () {
        $this->post(route('store.cart.products.store', 1), ['cycle' => 'triennially'])
            ->assertSessionHasErrors(['cycle' => 'Choose one of the available billing cycles.']);
    });

    it('refuses to add an out-of-stock product', function () {
        $this->fakes->catalog->addProduct(2, 'Pro Hosting', stock: 0);

        $this->post(route('store.cart.products.store', 2), ['cycle' => 'monthly'])
            ->assertSessionHasErrors(['product_id' => 'Pro Hosting is out of stock.']);

        expect(Cart::query()->count())->toBe(0);
    });

    it('requires a domain for products that need one', function () {
        $this->fakes->catalog->addProduct(2, 'Business Hosting', requiresDomain: true);

        $this->post(route('store.cart.products.store', 2), ['cycle' => 'monthly'])
            ->assertSessionHasErrors(['domain_name' => 'Choose a domain for this product.']);
    });

    it('rejects a domain already attached to an active service', function () {
        $this->fakes->catalog->addProduct(2, 'Business Hosting', requiresDomain: true);
        OrderItem::factory()->status(OrderItemStatus::Active)->create(['domain' => 'taken.com']);

        $this->post(route('store.cart.products.store', 2), ['cycle' => 'monthly', 'domain_mode' => 'existing', 'domain_name' => 'taken.com'])
            ->assertSessionHasErrors(['domain_name' => 'taken.com is already used by another service.']);
    });

    it('requires an EPP code for domain transfers', function () {
        $this->post(route('store.cart.domains.store'), ['domain' => 'acme.com', 'action' => 'transfer', 'years' => 1])
            ->assertSessionHasErrors(['epp_code']);
    });

    it('validates required custom fields and listed options', function () {
        $this->fakes->catalog->customFields[1] = [['id' => 5, 'name' => 'Hostname', 'type' => 'text', 'required' => true, 'options' => []]];
        $this->fakes->catalog->configOptions[1] = [['id' => 3, 'name' => 'Memory', 'type' => 'dropdown', 'values' => [['id' => 30, 'name' => '4 GB', 'pricing' => []]]]];

        $this->post(route('store.cart.products.store', 1), ['cycle' => 'monthly', 'config_options' => [3 => 99]])
            ->assertSessionHasErrors(['custom_fields.5' => 'Enter Hostname.', 'config_options.3' => 'Choose one of the listed options for Memory.']);
    });

    it('only offers the registration periods the TLD supports', function () {
        $this->post(route('store.cart.domains.store'), ['domain' => 'acme.com', 'action' => 'register', 'years' => 4])
            ->assertSessionHasErrors(['years' => 'Choose one of the registration periods offered for .com.']);
    });

    it('checks custom fields against the catalog regex, like a server hostname', function () {
        $this->fakes->catalog->customFields[1] = [[
            'id' => 5, 'name' => 'Hostname', 'type' => 'text', 'required' => true, 'options' => [],
            'regex' => '/^[a-z0-9.-]+\.[a-z]{2,}$/i', 'description' => 'Fully qualified, e.g. server1.example.com',
        ]];

        $this->post(route('store.cart.products.store', 1), ['cycle' => 'monthly', 'custom_fields' => [5 => 'not a hostname']])
            ->assertSessionHasErrors(['custom_fields.5' => 'Hostname is not in the expected format. Fully qualified, e.g. server1.example.com']);

        $this->post(route('store.cart.products.store', 1), ['cycle' => 'monthly', 'custom_fields' => [5 => 'server1.example.com']])
            ->assertSessionHasNoErrors();
        expect(Cart::query()->sole()->cartItems()[0]->customFields)->toBe([5 => 'server1.example.com']);
    });

    it('rejects a field whose catalog regex is invalid instead of skipping the check', function () {
        $this->fakes->catalog->customFields[1] = [['id' => 5, 'name' => 'Hostname', 'type' => 'text', 'required' => false, 'options' => [], 'regex' => '/[unclosed']];

        $this->post(route('store.cart.products.store', 1), ['cycle' => 'monthly', 'custom_fields' => [5 => 'web01']])
            ->assertSessionHasErrors(['custom_fields.5' => 'Hostname cannot be checked right now. Contact us to complete this order.']);
    });

    it('never accepts admin-only fields from customers', function () {
        $this->fakes->catalog->customFields[1] = [['id' => 6, 'name' => 'Internal notes', 'type' => 'textarea', 'required' => true, 'options' => [], 'admin_only' => true]];

        $this->post(route('store.cart.products.store', 1), ['cycle' => 'monthly', 'custom_fields' => [6 => 'let me in']])
            ->assertSessionHasNoErrors();

        expect(Cart::query()->sole()->cartItems()[0]->customFields)->toBe([]);
    });

    it('validates link and checkbox fields by type', function () {
        $this->fakes->catalog->customFields[1] = [
            ['id' => 7, 'name' => 'Website', 'type' => 'link', 'required' => false, 'options' => []],
            ['id' => 8, 'name' => 'Accept the AUP', 'type' => 'checkbox', 'required' => true, 'options' => []],
        ];

        $this->post(route('store.cart.products.store', 1), ['cycle' => 'monthly', 'custom_fields' => [7 => 'example dot com']])
            ->assertSessionHasErrors(['custom_fields.7', 'custom_fields.8' => 'Tick Accept the AUP to continue.']);
    });

    it('stores the EPP code encrypted', function () {
        $this->post(route('store.cart.domains.store'), ['domain' => 'acme.com', 'action' => 'transfer', 'years' => 1, 'epp_code' => 'SECRET-EPP'])
            ->assertRedirect(route('store.cart'));

        $cart = Cart::query()->sole();
        expect(json_encode($cart->items))->not->toContain('SECRET-EPP')
            ->and($cart->cartItems()[0]->eppCode)->toBe('SECRET-EPP');
    });

    it('updates an item in place', function () {
        $this->post(route('store.cart.products.store', 1), ['cycle' => 'monthly']);
        $key = Cart::query()->sole()->cartItems()[0]->key;

        $this->put(route('store.cart.items.update', $key), ['cycle' => 'annually'])->assertRedirect(route('store.cart'));

        $items = Cart::query()->sole()->cartItems();
        expect($items)->toHaveCount(1)->and($items[0]->cycle?->value)->toBe('annually');
    });

    it('removes an item', function () {
        $this->post(route('store.cart.products.store', 1), ['cycle' => 'monthly']);
        $key = Cart::query()->sole()->cartItems()[0]->key;

        $this->delete(route('store.cart.items.destroy', $key))->assertRedirect(route('store.cart'));

        expect(Cart::query()->sole()->isEmpty())->toBeTrue();
    });

    it('applies a valid promotion code and refuses an invalid one', function () {
        $this->post(route('store.cart.products.store', 1), ['cycle' => 'monthly']);
        $promotion = Promotion::factory()->percent('10')->create();

        $this->post(route('store.cart.promotion.store'), ['promotion_code' => 'nope'])
            ->assertSessionHasErrors(['promotion_code' => 'This promotion code is not valid.']);

        $this->post(route('store.cart.promotion.store'), ['promotion_code' => strtolower($promotion->code)])
            ->assertRedirect(route('store.cart'));

        $this->get(route('store.cart'))->assertInertia(fn (Assert $page) => $page
            ->where('cart.promotion.code', $promotion->code)
            ->where('cart.promotion.applied', true)
            ->where('cart.discount_total', 100));
    });

    it('applies a ?promo= link to the next cart write', function () {
        $promotion = Promotion::factory()->percent('10')->create();

        $this->get(route('store.index', ['promo' => $promotion->code]));
        $this->post(route('store.cart.products.store', 1), ['cycle' => 'monthly']);

        expect(Cart::query()->sole()->promotion_code)->toBe($promotion->code);
    });

    it('switches a guest cart to another currency', function () {
        Currency::factory()->inr()->create();
        $this->post(route('store.cart.products.store', 1), ['cycle' => 'monthly']);

        $this->put(route('store.currency'), ['currency' => 'INR'])->assertRedirect();

        expect(Cart::query()->sole()->currency)->toBe('INR');
    });
});

it('merges the guest cart into the client cart on login with guest items kept', function () {
    $client = Client::factory()->create(['currency' => 'USD']);
    $user = User::factory()->create(['email' => $client->email]);
    $client->users()->attach($user, ['is_owner' => true]);
    $this->fakes->catalog->addProduct(2, 'Business Hosting');
    OrdersFakes::cartFor($client, [OrdersFakes::productItem(2, domain: 'shared.com'), OrdersFakes::productItem(2, domain: 'mine.com')]);

    $this->post(route('store.cart.products.store', 1), ['cycle' => 'monthly', 'domain_mode' => 'existing', 'domain_name' => 'shared.com']);
    $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])->assertRedirect();

    $cart = Cart::query()->sole();
    $items = $cart->cartItems();
    expect($cart->client_id)->toBe($client->id)
        ->and(array_map(fn ($item) => [$item->productId, $item->domain()], $items))->toBe([[2, 'mine.com'], [1, 'shared.com']]);
});

it('prices a merged cart in the client currency', function () {
    Currency::factory()->inr()->create();
    $client = Client::factory()->create(['currency' => 'INR']);
    $user = User::factory()->create(['email' => $client->email]);
    $client->users()->attach($user, ['is_owner' => true]);

    $this->post(route('store.cart.products.store', 1), ['cycle' => 'monthly']);
    $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);

    $this->get(route('store.cart'))->assertInertia(fn (Assert $page) => $page->where('cart.currency', 'INR'));
});

it('prunes expired carts idempotently', function () {
    Cart::factory()->expired()->count(2)->create();
    Cart::factory()->create();

    $this->artisan('orders:prune-carts')->expectsOutput('Deleted 2 expired carts.')->assertSuccessful();
    $this->artisan('orders:prune-carts')->expectsOutput('Deleted 0 expired carts.')->assertSuccessful();

    expect(Cart::query()->count())->toBe(1);
});

it('does not count cancelled or fraud orders as using a domain', function () {
    $this->fakes->catalog->addProduct(2, 'Business Hosting', requiresDomain: true);
    OrderItem::factory()->for(Order::factory()->fraud())->create(['domain' => 'reuse.com']);

    $this->post(route('store.cart.products.store', 2), ['cycle' => 'monthly', 'domain_mode' => 'existing', 'domain_name' => 'reuse.com'])
        ->assertSessionHasNoErrors();
});

describe('rate limits', function () {
    it('does not let price previews use up the checkout budget', function () {
        $client = Client::factory()->withOwner()->create();
        $this->actingAs($client->users()->sole());
        OrdersFakes::cartFor($client, [OrdersFakes::productItem(1)]);

        foreach (range(1, 15) as $attempt) {
            $this->postJson(route('store.products.quote', 1), ['cycle' => 'monthly'])->assertOk();
        }

        $this->post(route('store.checkout.store'), ['payment_gateway' => 'bank_transfer', 'accept_tos' => true, 'idempotency_key' => 'rate-1'])
            ->assertRedirect();
    });

    it('limits promotion code attempts', function () {
        foreach (range(1, 10) as $attempt) {
            $this->post(route('store.cart.promotion.store'), ['promotion_code' => "GUESS{$attempt}"])->assertSessionHasErrors('promotion_code');
        }

        $this->post(route('store.cart.promotion.store'), ['promotion_code' => 'GUESS11'])->assertTooManyRequests();
    });

    it('caps how many ?promo= links a session can try', function () {
        $promotion = Promotion::factory()->percent('10')->create();
        $this->post(route('store.cart.products.store', 1), ['cycle' => 'monthly']);

        foreach (range(1, 5) as $attempt) {
            $this->delete(route('store.cart.promotion.destroy'));
            $this->get(route('store.index', ['promo' => "GUESS{$attempt}"]));
            $this->post(route('store.cart.products.store', 1), ['cycle' => 'monthly']);
        }

        $this->delete(route('store.cart.promotion.destroy'));
        $this->get(route('store.index', ['promo' => $promotion->code]));
        $this->post(route('store.cart.products.store', 1), ['cycle' => 'monthly']);

        expect(Cart::query()->sole()->promotion_code)->toBeNull();
    });
});
