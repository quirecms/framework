// CI only: the "ci-video" service's script (consent.mjs). Marks the page when the loader starts it.
export function start() {
    document.documentElement.dataset.ciVideo = 'started';
}

export function stop() {
    document.documentElement.dataset.ciVideo = 'stopped';
}
