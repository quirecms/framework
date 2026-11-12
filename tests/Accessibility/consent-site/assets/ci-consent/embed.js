// CI only: the script of [[ci_video]]'s embed. It counts its runs, so the check sees it ran once.
document.documentElement.dataset.ciEmbed = String(Number(document.documentElement.dataset.ciEmbed ?? 0) + 1);
