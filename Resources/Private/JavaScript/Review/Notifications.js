/**
 * The notifications of the review: the flash messages of the backend and the errors of a
 * request that failed.
 */

/**
 * @typedef {Object} Notification
 * @property {string} severity
 * @property {string} title
 * @property {string} message
 * @property {number} code
 */

/**
 * @typedef {Object} EventDetails
 * @property {XMLHttpRequest} xhr
 */

/**
 * @typedef {Object} HtmxEvent
 * @property {EventDetails} detail
 */

/**
 * Shows the messages of the backend: the flash messages of a request that worked and the
 * error of a request that failed.
 */
export const setupNotifications = () => {
    /**
     * Show flash messages after successful requests
     */
    htmx.on(
        'htmx:afterRequest',
        /** @param {HtmxEvent} e */ (e) => {
            const flashMessagesJson = e.detail.xhr.getResponseHeader('X-Flow-FlashMessages');
            if (!flashMessagesJson) {
                return;
            }

            /** @type Notification[] */
            const flashMessages = JSON.parse(flashMessagesJson);
            flashMessages.forEach(({ severity, title, message }) => {
                if (title) {
                    NeosCMS.Notification[severity.toLowerCase()](title, message);
                } else {
                    NeosCMS.Notification[severity.toLowerCase()](message);
                }
            });
        },
    );

    /**
     * Show error notifications for failed requests if no flash messages are present
     */
    htmx.on(
        'htmx:responseError',
        /** @param {HtmxEvent} e */ (e) => {
            const flashMessagesJson = e.detail.xhr.getResponseHeader('X-Flow-FlashMessages');
            if (flashMessagesJson) {
                return;
            }

            const { status, statusText } = e.detail.xhr;
            NeosCMS.Notification.error(`Error ${status}: ${statusText}`);
        },
    );
};
