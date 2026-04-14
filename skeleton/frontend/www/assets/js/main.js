/* Zero Framework - Main JS */

/* Remove loading class from header on DOMContentLoaded */
document.addEventListener('DOMContentLoaded', () => {
    const header = document.querySelector('shadow-header');
    if (header) {
        header.classList.remove('loading');
    }
});
