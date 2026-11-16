/* module.js — filename matches the module name, so this loads on every
   endpoint. An endpoint-scoped script would be assets/js/index.js. */

document.addEventListener('DOMContentLoaded', () => {
    console.debug('module.js loaded');
});
