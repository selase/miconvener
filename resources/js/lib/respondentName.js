const STORAGE_KEY = 'miconvener-respondent-name';

/** The display name a respondent has chosen for themselves, remembered per-browser for the quiz leaderboard. */
export function getRespondentName() {
    try {
        return window.localStorage.getItem(STORAGE_KEY) ?? '';
    } catch {
        return '';
    }
}

export function setRespondentName(name) {
    try {
        window.localStorage.setItem(STORAGE_KEY, name);
    } catch {
        // Ignore — the name just won't be remembered next time.
    }
}
