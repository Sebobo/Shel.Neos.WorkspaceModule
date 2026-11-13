/**
 * The popups of the review: the menus of the browser and the place they belong to.
 */
/**
 * Puts a popup of the review where it belongs.
 *
 * The popups are in the layer above the page, so they do not move with it - they have to
 * be put in place again when the page or the list of the documents is scrolled.
 *
 * @param {HTMLElement} popup
 * @param {HTMLElement} anchor the element the popup belongs to
 * @param {string} placement 'below' or 'above'
 * @param {string} alignment 'left' or 'right' - the edge of the popup that is aligned with
 *                        the edge of the anchor
 */
export const positionPopup = (popup, anchor, placement, alignment = 'left') => {
    const anchorPosition = anchor.getBoundingClientRect();
    const popupSize = popup.getBoundingClientRect();
    const margin = 8;

    if (placement === 'above') {
        popup.style.right = window.innerWidth - anchorPosition.right + 'px';
        popup.style.bottom = window.innerHeight - anchorPosition.top + 'px';
        popup.style.left = 'auto';
        popup.style.top = 'auto';
        return;
    }

    if (alignment === 'right') {
        // The right edge of the popup is aligned with the right edge of the item, so that
        // it does not cover the content beside the list.
        // The width of the window includes the scrollbar, the position of the item does
        // not - so the width of the page is the one to calculate with.
        const pageWidth = document.documentElement.clientWidth;
        const pageHeight = document.documentElement.clientHeight;
        popup.style.right =
            Math.max(margin, Math.min(pageWidth - anchorPosition.right, pageWidth - popupSize.width - margin)) + 'px';
        popup.style.top =
            Math.max(margin, Math.min(anchorPosition.bottom, pageHeight - popupSize.height - margin)) + 'px';
        popup.style.left = 'auto';
        popup.style.bottom = 'auto';
        return;
    }

    popup.style.left =
        Math.max(margin, Math.min(anchorPosition.left, window.innerWidth - popupSize.width - margin)) + 'px';
    popup.style.top =
        Math.max(margin, Math.min(anchorPosition.bottom, window.innerHeight - popupSize.height - margin)) + 'px';
    popup.style.right = 'auto';
    popup.style.bottom = 'auto';
};
/**
 * The popups with a button that opens them, so that they can be put in place again
 * when the page is scrolled.
 *
 * @type {Array<{popup: HTMLElement, trigger: HTMLElement}>}
 */
const popupsWithTrigger = [];

/**
 * The menus of the combo buttons and the list of the presets are popups of the browser:
 * they sit in the layer above the page, so that the footer of the core and the sidebar of
 * the review cannot cut them off, and only one of them can be open at a time.
 *
 * The browser does not know where a popup belongs - the script puts it next to the
 * button that opens it.
 */
export const setupPopovers = () => {
    document.querySelectorAll('[popover]').forEach((popup) => {
        const trigger = document.querySelector('[popovertarget="' + popup.id + '"]');
        if (!trigger) {
            return;
        }

        popupsWithTrigger.push({ popup, trigger });

        popup.addEventListener('beforetoggle', (event) => {
            if (event.newState !== 'open') {
                return;
            }
            positionPopup(popup, trigger, popup.getAttribute('data-placement') === 'below' ? 'below' : 'above');
        });

        popup.addEventListener('toggle', (event) => {
            trigger.setAttribute('aria-expanded', event.newState === 'open' ? 'true' : 'false');
        });

        // an option acts - the popup closes
        popup.querySelectorAll('a, .workspace-review-combo-option').forEach((option) => {
            option.addEventListener('click', () => {
                if (typeof popup.hidePopover === 'function') {
                    popup.hidePopover();
                }
            });
        });
    });
};

/**
 * Puts the popups that are open in place again - they do not move with the page.
 */
export const repositionOpenPopups = () => {
    popupsWithTrigger.forEach(({ popup, trigger }) => {
        if (popup.matches(':popover-open')) {
            positionPopup(popup, trigger, popup.getAttribute('data-placement') === 'below' ? 'below' : 'above');
        }
    });
};
