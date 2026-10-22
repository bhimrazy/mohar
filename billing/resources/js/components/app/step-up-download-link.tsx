import type { AnchorHTMLAttributes, MouseEvent } from 'react';

/** Fired on `window` when a download needs a recent password; `PasswordConfirmationGate` listens for it. */
export const STEP_UP_DOWNLOAD_EVENT = 'step-up:download';

/**
 * A link to a file behind the `password.recent` middleware. A plain click asks the server (a probe that never
 * reaches the controller) whether the password was confirmed recently: if so the browser downloads the file,
 * if not the confirm-password dialog opens first and the download starts after it. Without JavaScript, or on a
 * middle or modified click, the link is a normal GET, which the server sends to the confirm-password page.
 */
export function StepUpDownloadLink({
    href,
    onClick,
    ...props
}: AnchorHTMLAttributes<HTMLAnchorElement> & { href: string }) {
    const open = async (event: MouseEvent<HTMLAnchorElement>) => {
        onClick?.(event);

        if (
            event.defaultPrevented ||
            event.button !== 0 ||
            event.metaKey ||
            event.ctrlKey ||
            event.shiftKey ||
            event.altKey
        ) {
            return;
        }

        event.preventDefault();

        try {
            const response = await fetch(href, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-Step-Up-Check': '1',
                },
            });

            if (response.status === 423) {
                window.dispatchEvent(
                    new CustomEvent(STEP_UP_DOWNLOAD_EVENT, {
                        detail: { url: href },
                    }),
                );

                return;
            }
        } catch {
            // Offline or blocked: let the browser try the link itself.
        }

        window.location.assign(href);
    };

    return <a href={href} onClick={open} {...props} />;
}
