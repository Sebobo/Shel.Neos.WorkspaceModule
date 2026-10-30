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

document.addEventListener('DOMContentLoaded', () => {

    if (!window.htmx) {
        console.error('htmx is not loaded');
        return;
    }

    /**
     * Show flash messages after successful requests
     */
    htmx.on('htmx:afterRequest', /** @param {HtmxEvent} e */(e) => {
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
    });

    /**
     * Show error notifications for failed requests if no flash messages are present
     */
    htmx.on('htmx:responseError', /** @param {HtmxEvent} e */(e) => {
        const flashMessagesJson = e.detail.xhr.getResponseHeader('X-Flow-FlashMessages');
        if (flashMessagesJson) {
            return;
        }

        const { status, statusText } = e.detail.xhr;
        NeosCMS.Notification.error(`Error ${status}: ${statusText}`);
    });

    /**
     * The button that publishes the selected changes is only enabled when there is
     * something to publish. The changes of a document are swapped into the page, so the
     * state is updated after every answer as well.
     */
    const updatePublishSelectedButton = () => {
        const button = document.getElementById('publishSelectedButton');
        if (!button) {
            return;
        }

        const canPublish = button.getAttribute('data-can-publish') === 'true';
        const hasSelection = document.querySelectorAll('#publishOrDiscardNodes input[type="checkbox"]:checked').length > 0;
        button.disabled = !canPublish || !hasSelection;
    };

    document.body.addEventListener('change', (event) => {
        if (event.target.matches('#publishOrDiscardNodes input[type="checkbox"]')) {
            updatePublishSelectedButton();
        }
    });

    htmx.on('htmx:afterSwap', () => {
        updatePublishSelectedButton();
    });

    updatePublishSelectedButton();
});
