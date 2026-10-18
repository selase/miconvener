/** Keep one save intent across network retries; changing inputs starts a new one. */
export function libraryWriteKey(attempt, payload, file) {
    const signature = JSON.stringify(payload);
    if (
        !attempt.current ||
        attempt.current.signature !== signature ||
        attempt.current.file !== file
    ) {
        attempt.current = { signature, file, key: crypto.randomUUID() };
    }
    return attempt.current.key;
}
