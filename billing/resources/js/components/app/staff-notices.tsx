import { Link, router } from '@inertiajs/react';
import { Info, X } from 'lucide-react';
import StaffNoticeController from '@/actions/App/Modules/Core/Http/Controllers/Admin/StaffNoticeController';
import { Button } from '@/components/ui/button';

/** Matches Core's Admin\DashboardController notices. */
export type StaffNoticeItem = {
    key: string;
    title: string;
    body: string;
    href: string | null;
};

/** One-time messages for the signed-in staff member; dismissing one hides it for that person only. */
export function StaffNotices({ notices }: { notices: StaffNoticeItem[] }) {
    if (notices.length === 0) {
        return null;
    }

    return (
        <div className="grid gap-3">
            {notices.map((notice) => (
                <section
                    key={notice.key}
                    aria-label={notice.title}
                    className="flex items-start gap-3 rounded-xl border border-info/30 bg-info-subtle p-4 text-info-text"
                >
                    <Info
                        className="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    <div className="min-w-0 flex-1 space-y-1 text-sm">
                        <h2 className="font-medium">{notice.title}</h2>
                        <p>{notice.body}</p>
                        {notice.href && (
                            <Link
                                href={notice.href}
                                className="inline-block font-medium underline underline-offset-4"
                            >
                                Open settings
                            </Link>
                        )}
                    </div>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-7 shrink-0"
                        aria-label={`Dismiss: ${notice.title}`}
                        onClick={() =>
                            router.post(
                                StaffNoticeController.dismiss(notice.key),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        <X aria-hidden="true" />
                    </Button>
                </section>
            ))}
        </div>
    );
}
