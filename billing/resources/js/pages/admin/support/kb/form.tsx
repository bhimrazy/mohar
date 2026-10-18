import { Form, Head } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import { useState } from 'react';
import KbArticleController from '@/actions/App/Modules/Support/Http/Controllers/Admin/KbArticleController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { Field } from '@/components/app/field';
import { FormActions } from '@/components/app/form-actions';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { Markdown } from '@/components/support/markdown';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import type { SelectOption } from '@/types/app';

type Article = {
    id: number;
    title: string;
    slug: string;
    body: string;
    body_html: string;
    is_published: boolean;
    is_private: boolean;
    sort_order: number;
    tags: string[];
    categories: string[];
    views: number;
    votes_up: number;
    votes_down: number;
    public_url: string;
};

export default function ArticleForm({
    article,
    categories,
}: {
    article?: Article;
    categories: SelectOption[];
}) {
    const [published, setPublished] = useState(article?.is_published ?? false);
    const [isPrivate, setIsPrivate] = useState(article?.is_private ?? false);
    const [selected, setSelected] = useState<string[]>(
        article?.categories ?? [],
    );
    const action = article
        ? KbArticleController.update.form(article.id)
        : KbArticleController.store.form();

    return (
        <>
            <Head title={article ? article.title : 'New article'} />
            <Page>
                <PageHeader
                    back={{
                        href: KbArticleController.index(),
                        label: 'Knowledge base',
                    }}
                    title={article ? 'Edit article' : 'New article'}
                    status={
                        article
                            ? article.is_published
                                ? 'published'
                                : 'draft'
                            : undefined
                    }
                    meta={
                        article && (
                            <span>
                                {article.views.toLocaleString()} views ·{' '}
                                {article.votes_up} found it helpful
                            </span>
                        )
                    }
                    actions={
                        article?.is_published && (
                            <Button variant="outline" asChild>
                                <a
                                    href={article.public_url}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <ExternalLink />
                                    View article
                                </a>
                            </Button>
                        )
                    }
                />
                <Form {...action} className="grid gap-4 lg:grid-cols-3">
                    {({ errors, processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="is_published"
                                value={published ? '1' : '0'}
                            />
                            <input
                                type="hidden"
                                name="is_private"
                                value={isPrivate ? '1' : '0'}
                            />
                            {selected.map((id) => (
                                <input
                                    key={id}
                                    type="hidden"
                                    name="categories[]"
                                    value={id}
                                />
                            ))}
                            <div className="flex min-w-0 flex-col gap-4 lg:col-span-2">
                                <SectionCard>
                                    <div className="grid gap-6">
                                        <Field
                                            htmlFor="title"
                                            label="Title"
                                            error={errors.title}
                                        >
                                            <Input
                                                id="title"
                                                name="title"
                                                defaultValue={article?.title}
                                                required
                                                aria-invalid={!!errors.title}
                                            />
                                        </Field>
                                        <Tabs defaultValue="write">
                                            <TabsList>
                                                <TabsTrigger value="write">
                                                    Write
                                                </TabsTrigger>
                                                {article && (
                                                    <TabsTrigger value="preview">
                                                        Preview
                                                    </TabsTrigger>
                                                )}
                                            </TabsList>
                                            <TabsContent
                                                value="write"
                                                className="pt-3"
                                            >
                                                <Field
                                                    htmlFor="body"
                                                    label="Content"
                                                    hint="Markdown. Raw HTML is removed when rendered."
                                                    error={errors.body}
                                                >
                                                    <Textarea
                                                        id="body"
                                                        name="body"
                                                        rows={18}
                                                        defaultValue={
                                                            article?.body
                                                        }
                                                        className="font-mono text-sm"
                                                        required
                                                        aria-invalid={
                                                            !!errors.body
                                                        }
                                                        aria-describedby="body-description"
                                                    />
                                                </Field>
                                            </TabsContent>
                                            {article && (
                                                <TabsContent
                                                    value="preview"
                                                    className="rounded-lg border p-4"
                                                >
                                                    <p className="mb-3 text-xs text-muted-foreground">
                                                        Preview of the last
                                                        saved version.
                                                    </p>
                                                    <Markdown
                                                        html={article.body_html}
                                                    />
                                                </TabsContent>
                                            )}
                                        </Tabs>
                                    </div>
                                </SectionCard>
                            </div>
                            <div className="flex min-w-0 flex-col gap-4">
                                <SectionCard title="Publishing">
                                    <div className="grid gap-3">
                                        <div className="flex items-center justify-between gap-4 rounded-lg border p-3">
                                            <label
                                                htmlFor="published"
                                                className="text-sm font-medium"
                                            >
                                                Published
                                            </label>
                                            <Switch
                                                id="published"
                                                checked={published}
                                                onCheckedChange={setPublished}
                                            />
                                        </div>
                                        <div className="flex items-center justify-between gap-4 rounded-lg border p-3">
                                            <div className="space-y-0.5">
                                                <label
                                                    htmlFor="private"
                                                    className="text-sm font-medium"
                                                >
                                                    Login required
                                                </label>
                                                <p className="text-xs text-muted-foreground">
                                                    Only signed-in clients can
                                                    read it.
                                                </p>
                                            </div>
                                            <Switch
                                                id="private"
                                                checked={isPrivate}
                                                onCheckedChange={setIsPrivate}
                                            />
                                        </div>
                                        <Field
                                            htmlFor="slug"
                                            label="Slug"
                                            optional
                                            error={errors.slug}
                                        >
                                            <Input
                                                id="slug"
                                                name="slug"
                                                defaultValue={article?.slug}
                                            />
                                        </Field>
                                        <Field
                                            htmlFor="tags"
                                            label="Tags"
                                            optional
                                            hint="Comma-separated, used by search."
                                            error={errors.tags}
                                        >
                                            <Input
                                                id="tags"
                                                name="tags"
                                                defaultValue={article?.tags.join(
                                                    ', ',
                                                )}
                                                aria-describedby="tags-description"
                                            />
                                        </Field>
                                        <Field
                                            htmlFor="sort_order"
                                            label="Sort order"
                                            optional
                                            error={errors.sort_order}
                                        >
                                            <Input
                                                id="sort_order"
                                                name="sort_order"
                                                type="number"
                                                min={0}
                                                defaultValue={
                                                    article?.sort_order ?? 0
                                                }
                                            />
                                        </Field>
                                    </div>
                                </SectionCard>
                                <SectionCard title="Categories">
                                    <ul className="grid gap-2">
                                        {categories.map((category) => (
                                            <li
                                                key={category.value}
                                                className="flex items-center gap-2"
                                            >
                                                <Checkbox
                                                    id={`category-${category.value}`}
                                                    checked={selected.includes(
                                                        category.value,
                                                    )}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        setSelected(
                                                            (current) =>
                                                                checked
                                                                    ? [
                                                                          ...current,
                                                                          category.value,
                                                                      ]
                                                                    : current.filter(
                                                                          (
                                                                              id,
                                                                          ) =>
                                                                              id !==
                                                                              category.value,
                                                                      ),
                                                        )
                                                    }
                                                />
                                                <Label
                                                    htmlFor={`category-${category.value}`}
                                                    className="font-normal"
                                                >
                                                    {category.label}
                                                </Label>
                                            </li>
                                        ))}
                                        {categories.length === 0 && (
                                            <li className="text-sm text-muted-foreground">
                                                Create categories on the
                                                knowledge base page.
                                            </li>
                                        )}
                                    </ul>
                                </SectionCard>
                                <FormActions
                                    status={
                                        article && (
                                            <ConfirmDialog
                                                trigger={
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        className="text-destructive"
                                                    >
                                                        Delete
                                                    </Button>
                                                }
                                                title={`Delete "${article.title}"?`}
                                                description="The article is removed from the knowledge base. This can't be undone."
                                                confirmLabel="Delete article"
                                                action={KbArticleController.destroy(
                                                    article.id,
                                                )}
                                            />
                                        )
                                    }
                                >
                                    <Button type="submit" disabled={processing}>
                                        {processing && <Spinner />}
                                        {article
                                            ? 'Save article'
                                            : 'Create article'}
                                    </Button>
                                </FormActions>
                            </div>
                        </>
                    )}
                </Form>
            </Page>
        </>
    );
}
