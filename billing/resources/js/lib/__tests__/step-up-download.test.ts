import { describe, expect, it } from 'vitest';
import {
    requestStepUpDownload,
    STEP_UP_DOWNLOAD_EVENT,
} from '@/components/app/step-up-download-link';

describe('requestStepUpDownload', () => {
    it('is handled when a gate cancels the event, and passes the url', () => {
        const target = new EventTarget();
        let url = '';
        target.addEventListener(STEP_UP_DOWNLOAD_EVENT, (event) => {
            event.preventDefault();
            url = (event as CustomEvent<{ url: string }>).detail.url;
        });

        expect(requestStepUpDownload(target, '/file.csv')).toBe(true);
        expect(url).toBe('/file.csv');
    });

    it('is not handled on a layout with no gate, so the link navigates to the server', () => {
        expect(requestStepUpDownload(new EventTarget(), '/file.csv')).toBe(
            false,
        );
    });
});
