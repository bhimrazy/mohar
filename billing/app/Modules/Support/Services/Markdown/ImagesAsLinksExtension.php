<?php

namespace App\Modules\Support\Services\Markdown;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;
use League\CommonMark\Util\RegexHelper;
use Stringable;

/**
 * Renders Markdown images as plain links, so user content can never load remote resources
 * (tracking pixels) in staff or customer browsers.
 */
final class ImagesAsLinksExtension implements ExtensionInterface, NodeRendererInterface
{
    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addRenderer(Image::class, $this, 100);
    }

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): Stringable
    {
        if (! $node instanceof Image) {
            return new HtmlElement('span', [], $childRenderer->renderNodes($node->children()));
        }

        $label = trim($childRenderer->renderNodes($node->children()));
        $label = $label !== '' ? $label : e($node->getUrl());

        if (RegexHelper::isLinkPotentiallyUnsafe($node->getUrl())) {
            return new HtmlElement('span', [], $label);
        }

        return new HtmlElement('a', [
            'href' => $node->getUrl(),
            'rel' => 'nofollow noopener noreferrer',
            'target' => '_blank',
        ], '🖼 '.$label);
    }
}
