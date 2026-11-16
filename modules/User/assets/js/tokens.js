/**
 * API Token Management
 * Handles create, copy, and delete operations for API tokens.
 */
document.addEventListener('DOMContentLoaded', () => {

    const createBtn      = document.getElementById('createTokenBtn');
    const createDialog   = document.getElementById('createTokenDialog');
    const createForm     = document.getElementById('createTokenForm');
    const cancelBtn      = document.getElementById('cancelCreateBtn');
    const showDialog     = document.getElementById('showTokenDialog');
    const tokenValue     = document.getElementById('tokenValue');
    const copyBtn        = document.getElementById('copyTokenBtn');
    const doneBtn        = document.getElementById('doneTokenBtn');

    // -- Create Token --
    createBtn.addEventListener('click', () => {
        createDialog.showModal();
    });

    cancelBtn.addEventListener('click', () => {
        createDialog.close();
    });

    createForm.addEventListener('submit', async (e) => {
        e.preventDefault();

        const formData = new FormData(createForm);

        try {
            const res = await fetch('/user/create-token', {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: formData
            });
            const json = await res.json();

            if (json.data && json.data.token) {
                createDialog.close();
                createForm.reset();

                // Show the token
                tokenValue.textContent = json.data.token;
                showDialog.showModal();
            } else {
                alert(json.data?.error || 'Failed to create token');
            }
        } catch (err) {
            alert('Network error. Please try again.');
        }
    });

    // -- Copy Token --
    copyBtn.addEventListener('click', async () => {
        const text = tokenValue.textContent;
        try {
            await navigator.clipboard.writeText(text);
            copyBtn.querySelector('.material-symbols-outlined').textContent = 'check';
            setTimeout(() => {
                copyBtn.querySelector('.material-symbols-outlined').textContent = 'content_copy';
            }, 2000);
        } catch {
            // Fallback: select the text
            const range = document.createRange();
            range.selectNodeContents(tokenValue);
            window.getSelection().removeAllRanges();
            window.getSelection().addRange(range);
        }
    });

    // -- Done (close show dialog and refresh) --
    doneBtn.addEventListener('click', () => {
        showDialog.close();
        window.location.reload();
    });

    // -- Close dialogs on backdrop click --
    [createDialog, showDialog].forEach(dialog => {
        dialog.addEventListener('click', (e) => {
            if (e.target === dialog) {
                dialog.close();
                // Reload if closing the show-token dialog to refresh the list
                if (dialog === showDialog) {
                    window.location.reload();
                }
            }
        });
    });

    // -- Delete Token --
    document.querySelectorAll('.token-delete-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const tokenId = btn.dataset.tokenId;
            const row = btn.closest('.token-row');
            const label = row.querySelector('.token-label').textContent.trim();

            if (!confirm(`Revoke token "${label}"? Any devices using it will lose access.`)) {
                return;
            }

            try {
                const res = await fetch(`/user/delete-token/${tokenId}`, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' }
                });
                const json = await res.json();

                if (json.message || (json.data && !json.data.error)) {
                    row.remove();

                    // If no more tokens, show empty state
                    const list = document.querySelector('.tokens-list');
                    if (list && list.children.length === 0) {
                        list.outerHTML = '<p class="profile-empty">No API tokens created yet.</p>';
                    }
                } else {
                    alert(json.data?.error || 'Failed to delete token');
                }
            } catch {
                alert('Network error. Please try again.');
            }
        });
    });

});
