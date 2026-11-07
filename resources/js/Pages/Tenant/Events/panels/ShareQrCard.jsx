import { Download } from 'lucide-react';

const PRINT_SIZE = 1200;
const QUIET_ZONE = 80;

function fileStem(event) {
    return `${event.slug ?? 'event'}-register-qr`;
}

function saveBlob(blob, filename) {
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    anchor.click();
    URL.revokeObjectURL(url);
}

/**
 * A PNG is what a print shop or a slide wants, and the browser can make one
 * from the SVG without another request. White padding is added around the code
 * because a scanner needs a clear margin to find it.
 */
function downloadPng(qr, filename) {
    const image = new Image();
    image.onload = () => {
        const canvas = document.createElement('canvas');
        canvas.width = PRINT_SIZE;
        canvas.height = PRINT_SIZE;
        const context = canvas.getContext('2d');
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, PRINT_SIZE, PRINT_SIZE);
        context.drawImage(
            image,
            QUIET_ZONE,
            QUIET_ZONE,
            PRINT_SIZE - QUIET_ZONE * 2,
            PRINT_SIZE - QUIET_ZONE * 2
        );
        canvas.toBlob((blob) => blob && saveBlob(blob, filename), 'image/png');
    };
    image.src = qr;
}

async function downloadSvg(qr, filename) {
    const response = await fetch(qr);
    saveBlob(await response.blob(), filename);
}

/**
 * The event's own QR code: scanning it opens the public page, where a guest
 * books. Meant for posters, flyers and slides, as opposed to the per-ticket
 * codes used at the door.
 */
export default function ShareQrCard({ event, qr }) {
    if (!qr) {
        return null;
    }

    const stem = fileStem(event);

    return (
        <div className="mt-4 flex flex-wrap items-center gap-5 border border-border p-4">
            <img
                src={qr}
                alt={`QR code that opens the registration page for ${event.name}`}
                className="size-36 bg-white p-2"
            />
            <div className="min-w-0 flex-1 space-y-3">
                <div>
                    <h4 className="text-sm font-semibold text-ink">Scan to register</h4>
                    <p className="text-sm text-ink-secondary">
                        Put this on a poster, flyer or slide. Scanning it opens your public event
                        page.
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <button
                        type="button"
                        onClick={() => downloadPng(qr, `${stem}.png`)}
                        className="inline-flex h-control items-center gap-2 rounded-md border border-border bg-surface px-4 text-sm font-medium text-ink hover:border-border-strong hover:bg-surface-hover"
                    >
                        <Download className="size-4" aria-hidden="true" />
                        Download PNG
                    </button>
                    <button
                        type="button"
                        onClick={() => downloadSvg(qr, `${stem}.svg`)}
                        className="inline-flex h-control items-center gap-2 rounded-md border border-border bg-surface px-4 text-sm font-medium text-ink hover:border-border-strong hover:bg-surface-hover"
                    >
                        <Download className="size-4" aria-hidden="true" />
                        Download SVG
                    </button>
                </div>
            </div>
        </div>
    );
}
