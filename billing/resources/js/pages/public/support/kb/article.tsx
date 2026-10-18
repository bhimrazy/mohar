import { Head, Link, router } from '@inertiajs/react';
import { Lock, ThumbsDown, ThumbsUp } from 'lucide-react';
import KnowledgebaseController from '@/actions/App/Modules/Support/Http/Controllers/Public/KnowledgebaseController';
import GuestTicketController from '@/actions/App/Modules/Support/Http/Controllers/Public/GuestTicketController';
import { DateTime } from '@/components/app/date';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { Markdown } from '@/components/support/markdown';
import { PublicSupportNav } from '@/components/support/public-nav';
import { ArticleList } from '@/components/support/kb-article-list';
import type {
    KbArticleItem,
    KbCategoryCard,
} from '@/components/support/kb-article-list';
import { Button } from '@/components/ui/button';

type Props = {
    article: {
        title: string;
        slug: string;
        body_html: string;
        tags: string[];
        views: number;
        votes_up: number;
        votes_down: number;
        is_private: boolean;
        updated_at: string | null;
        has_voted: boolean;
    };
    categories: KbCategoryCard[];
    related: KbArticleItem[];
};

export default function KbArticle({ article, categories, related }: Props) {
    const vote = (helpful: 'yes' | 'no') =>
        router.post(
            KnowledgebaseController.vote.url(article.slug),
            { helpful },
            { preserveScroll: true },
        );
    const category = categories[0];

    return (
        <>
            <Head title={article.title} />
            <Page>
                <PublicSupportNav />
                <PageHeader
                    back={
                        category
                            ? {
                                  href: KnowledgebaseController.category(
                                      category.slug,
                                  ),
                                  label: category.name,
                              }
                            : {
                                  href: KnowledgebaseController.index(),
                                  label: 'Knowledge base',
                              }
                    }
                    title={article.title}
                    badges={
                        article.is_private && (
                            <Lock
                                className="size-4 text-muted-foreground"
                                aria-label="Clients only"
                            />
                        )
                    }
                    meta={
                        <>
                            <span>
                                Updated <DateTime value={article.updated_at} />
                            </span>
                            <span aria-hidden="true">·</span>
                            <span>{article.views.toLocaleString()} views</span>
                        </>
                    }
                />
                <div className="grid gap-4 lg:grid-cols-3">
                    <div className="flex min-w-0 flex-col gap-4 lg:col-span-2">
                        <SectionCard>
                            <Markdown html={article.body_html} />
                            {article.tags.length > 0 && (
                                <div className="mt-6 flex flex-wrap gap-2 border-t pt-4">
                                    {article.tags.map((tag) => (
                                        <Link
                                            key={tag}
                                            href={KnowledgebaseController.index(
                                                { query: { q: tag } },
                                            )}
                                            className="rounded-full bg-muted px-2.5 py-0.5 text-xs text-muted-foreground hover:text-foreground"
                                        >
                                            {tag}
                                        </Link>
                                    ))}
                                </div>
                            )}
                        </SectionCard>
                        <SectionCard>
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <p className="text-sm font-medium">
                                    {article.has_voted
                                        ? 'Thanks for your feedback.'
                                        : 'Was this article helpful?'}
                                </p>
                                <div className="flex gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={article.has_voted}
                                        onClick={() => vote('yes')}
                                    >
                                        <ThumbsUp />
                                        Yes
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={article.has_voted}
                                        onClick={() => vote('no')}
                                    >
                                        <ThumbsDown />
                                        No
                                    </Button>
                                </div>
                            </div>
                            <p className="mt-2 text-xs text-muted-foreground">
                                {article.votes_up} of{' '}
                                {article.votes_up + article.votes_down} people
                                found this helpful.
                            </p>
                        </SectionCard>
                    </div>
                    <div className="flex min-w-0 flex-col gap-4">
                        {related.length > 0 && (
                            <SectionCard title="Related articles" flush>
                                <ArticleList articles={related} />
                            </SectionCard>
                        )}
                        <SectionCard
                            title="Still stuck?"
                            description="Our team can help with anything the articles don't cover."
                        >
                            <Button asChild variant="outline">
                                <Link href={GuestTicketController.create()}>
                                    Contact support
                                </Link>
                            </Button>
                        </SectionCard>
                    </div>
                </div>
            </Page>
        </>
    );
}
