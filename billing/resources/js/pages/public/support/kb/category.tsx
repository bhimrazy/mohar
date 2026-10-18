import { Link } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import { BookOpen } from 'lucide-react';
import KnowledgebaseController from '@/actions/App/Modules/Support/Http/Controllers/Public/KnowledgebaseController';
import { EmptyState } from '@/components/app/empty-state';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { PublicSupportNav } from '@/components/support/public-nav';
import { ArticleList } from '@/components/support/kb-article-list';
import type {
    KbArticleItem,
    KbCategoryCard,
} from '@/components/support/kb-article-list';

type Props = {
    category: KbCategoryCard & { parent: KbCategoryCard | null };
    children: KbCategoryCard[];
    articles: KbArticleItem[];
};

export default function KbCategory({ category, children, articles }: Props) {
    return (
        <>
            <Head title={category.name} />
            <Page>
                <PublicSupportNav />
                <PageHeader
                    back={
                        category.parent
                            ? {
                                  href: KnowledgebaseController.category(
                                      category.parent.slug,
                                  ),
                                  label: category.parent.name,
                              }
                            : {
                                  href: KnowledgebaseController.index(),
                                  label: 'Knowledge base',
                              }
                    }
                    title={category.name}
                    description={category.description}
                />
                {children.length > 0 && (
                    <div className="flex flex-wrap gap-2">
                        {children.map((child) => (
                            <Link
                                key={child.slug}
                                href={KnowledgebaseController.category(
                                    child.slug,
                                )}
                                className="rounded-full border px-3 py-1 text-sm transition-colors hover:bg-muted focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                            >
                                {child.name}
                            </Link>
                        ))}
                    </div>
                )}
                <SectionCard flush>
                    {articles.length > 0 ? (
                        <ArticleList articles={articles} />
                    ) : (
                        <EmptyState
                            icon={BookOpen}
                            title="No articles in this category yet"
                        />
                    )}
                </SectionCard>
            </Page>
        </>
    );
}
