const STORAGE_KEY = 'miconvener-respondent-token';

/** A stable, anonymous per-browser id used to dedupe poll responses without accounts. */
export default function respondentToken() {
    try {
        let token = window.localStorage.getItem(STORAGE_KEY);
        if (!token) {
            token = crypto.randomUUID();
            window.localStorage.setItem(STORAGE_KEY, token);
        }
        return token;
    } catch {
        return crypto.randomUUID();
    }
}
