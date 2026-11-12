// The fixture video service: marks the page when it runs, so tests can see when consent started it.
export function start({config}) {
    document.documentElement.dataset.fixtureVideo = `started:${config.channel}`;
}

export function stop() {
    document.documentElement.dataset.fixtureVideo = 'stopped';
}
