/*
 * Dates of the workspace overview.
 *
 * A date of the last week is spoken relative ("just now", "5 minutes ago") - a change of a
 * few minutes ago is worth more to a reviewer than its position in the day. The relative
 * words are the ones of the module, so that the overview and the review speak the same.
 */
declare type TranslateFunction = (id: string, label?: string, args?: string[]) => string;

const LAST_DAY_MS = 24 * 60 * 60 * 1000;
const LAST_WEEK_MS = 7 * LAST_DAY_MS;

/**
 * The relative label of a date of the last week, or null when it is older - the caller
 * renders the formatted date for it.
 */
export function relativeDate(datetime: string | number, translate: TranslateFunction): string | null {
    const age = Date.now() - new Date(datetime).getTime();
    if (age < 0 || age > LAST_WEEK_MS) {
        return null;
    }
    if (age < 60 * 1000) {
        return translate('relative.justNow');
    }
    if (age < 60 * 60 * 1000) {
        const minutes = Math.floor(age / (60 * 1000));

        return translate(minutes === 1 ? 'relative.minute' : 'relative.minutes', '', [String(minutes)]);
    }
    if (age < LAST_DAY_MS) {
        const hours = Math.floor(age / (60 * 60 * 1000));

        return translate(hours === 1 ? 'relative.hour' : 'relative.hours', '', [String(hours)]);
    }
    if (age < 2 * LAST_DAY_MS) {
        return translate('relative.yesterday');
    }
    const days = Math.floor(age / LAST_DAY_MS);

    return translate('relative.days', '', [String(days)]);
}

/**
 * The relative label of a date of the last week, the formatted date for an older one.
 */
export function dateLabel(datetime: string | number, translate: TranslateFunction): string {
    return relativeDate(datetime, translate) ?? formatDate(datetime);
}

export function formatDate(datetime: string | number): string {
    return new Date(datetime).toLocaleString(undefined, {
        year: '2-digit',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}
