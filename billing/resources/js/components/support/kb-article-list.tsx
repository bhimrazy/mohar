import { Link } from '@inertiajs/react';
import { BookOpen, ChevronRight, Lock } from 'lucide-react';
import KnowledgebaseController from '@/actions/App/Modules/Support/Http/Controllers/Public/KnowledgebaseController';

export type KbCategoryCard = {
    name: string;
    slug: string;
    description: string | null;
};
export type KbArticleItem = {
    title: string;
    slug: string;
    excerpt: string;
    views: number;
    is_private: boolean;
};

/** Linked list of knowledge base articles with a one-line excerpt. */
export function ArticleList({ articles }: { articles: KbArticleItem[] }) {
    return (
        <ul className="divide-y">
            {articles.map((article) => (
                <li key={article.slug}>
                    <Link
                        href={KnowledgebaseController.article(article.slug)}
                        className="group flex items-start gap-3 px-4 py-3 transition-colors hover:bg-muted/40 focus-visible:bg-muted/40 focus-visible:outline-none sm:px-6"
                    >
                        <BookOpen
                            className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                            aria-hidden
                        />
                        <span className="min-w-0 flex-1">
                            <span className="flex items-center gap-2 text-sm font-medium group-hover:underline">
                                {article.title}
                                {article.is_private && (
                                    <Lock
                                        className="size-3.5 text-muted-foreground"
                                        aria-label="Clients only"
                                    />
                                )}
                            </span>
                            <span className="line-clamp-1 text-sm text-muted-foreground">
                                {article.excerpt}
                            </span>
                        </span>
                        <ChevronRight
                            className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                            aria-hidden
                        />
                    </Link>
                </li>
            ))}
        </ul>
    );
}
