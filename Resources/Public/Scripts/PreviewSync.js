/**
 * The script of the scroll sync of the preview of a document.
 *
 * The pages of the preview are pages of a site: when the site is not the one the review
 * is opened on, the page in a preview frame cannot be reached by the review - the review
 * is on the domain of another site. The pages tell the review about their scrolls, and
 * scroll when the review tells them to.
 *
 * The review injects this script into the responses of the frontend preview
 * (PreviewScriptMiddleware); it is loaded in a page and only talks when it is in a frame
 * of the review.
 */
(function () {
    if (window.parent === null || window.parent === window) {
        // the page is open on its own - there is no review to talk to
        return;
    }

    const tellReview = (scrollPosition, pageHeight, frameHeight) => {
        try {
            window.parent.postMessage(
                {
                    source: 'workspace-review-preview',
                    type: 'scroll',
                    y: scrollPosition,
                    height: pageHeight,
                    innerHeight: frameHeight,
                },
                '*',
            );
        } catch (error) {
            // the review is not reachable for this page, e.g. without a document as parent
        }
    };

    window.addEventListener(
        'scroll',
        () => {
            tellReview(
                window.scrollY,
                document.documentElement.scrollHeight,
                window.innerHeight,
            );
        },
        { passive: true },
    );

    window.addEventListener('message', (event) => {
        const data = event.data;
        if (
            data === null ||
            typeof data !== 'object' ||
            data.source !== 'workspace-review' ||
            data.type !== 'scroll' ||
            typeof data.y !== 'number'
        ) {
            return;
        }
        window.scrollTo(0, data.y);
    });
})();
