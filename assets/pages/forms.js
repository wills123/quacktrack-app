/**
 * forms.js — Forms page behaviour
 * Handles: question editor options and new-form navigation
 */
(function() {
    'use strict';

    /* ── Helpers ──────────────────────────────────────────────── */
    function escAttr(s) {
        return String(s || '')
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;');
    }

    /* ── New-form button navigation ───────────────────────────── */
    function openNewForm() {
        window.location.href = '/forms?tab=forms';
    }

    ['frmNewFormBtn', 'frmSidebarNewBtn'].forEach((id) => {
        const btn = document.getElementById(id);
        if (btn) {
            btn.addEventListener('click', openNewForm);
        }
    });

    /* ── Question editor — options builder ────────────────────── */
    const editorForm = document.getElementById('questionEditorForm');
    if (!editorForm) {
        return;
    }

    const typeSelect = editorForm.querySelector('select[name="type"]');
    const optionsWrap = document.getElementById('frmOptionsWrap');
    const optionsField = document.getElementById('frmOptionsFields');
    const optHidden = document.getElementById('frmOptionsHidden');
    const optCount = document.getElementById('frmOptionsCount');
    const optError = document.getElementById('frmOptionsError');

    function getOptionInputs() {
        return optionsField
            ? Array.prototype.slice.call(optionsField.querySelectorAll('.frm-option-input'))
            : [];
    }

    function buildHidden() {
        if (!optHidden) {
            return;
        }
        optHidden.value = getOptionInputs()
            .map((i) => {
                return (i.value || '').trim();
            })
            .filter(Boolean)
            .join('\n');
    }

    function updateCount() {
        if (!optCount) {
            return;
        }
        const n = getOptionInputs().filter((i) => {
            return (i.value || '').trim();
        }).length;
        optCount.textContent = n ? (n + ' option' + (n !== 1 ? 's' : '')) : 'No options yet';
    }

    function makeRow(val) {
        const row = document.createElement('div');
        row.className = 'frm-option-row' + (val ? '' : ' frm-option-row--empty');
        row.setAttribute('draggable', 'true');
        row.innerHTML =
      '<button type="button" class="frm-option-drag" aria-label="Drag to reorder">&#8801;</button>' +
      '<input class="frm-option-input" type="text" value="' + escAttr(val || '') + '" placeholder="Option text">' +
      '<button type="button" class="frm-option-remove" title="Remove">&#10005;</button>';
        return row;
    }

    function ensureTrailing() {
        if (!optionsField) {
            return;
        }
        const inputs = getOptionInputs();
        if (!inputs.length || (inputs[inputs.length - 1].value || '').trim() !== '') {
            optionsField.appendChild(makeRow(''));
        }
    }

    function showError(msg) {
        if (!optError) {
            return;
        }
        optError.textContent = msg || '';
        optError.classList.toggle('is-visible', !!msg);
    }

    function syncVisible() {
        const t = (typeSelect ? typeSelect.value : '').toLowerCase();
        const isChoice = t === 'single_choice' || t === 'multi_choice';
        if (optionsWrap) {
            optionsWrap.style.display = isChoice ? 'block' : 'none';
        }
        buildHidden();
        updateCount();
    }

    if (typeSelect) {
        typeSelect.addEventListener('change', syncVisible);
    }

    if (optionsField) {
    // Input changes
        optionsField.addEventListener('input', (e) => {
            if (!e.target.classList.contains('frm-option-input')) {
                return;
            }
            const row = e.target.closest('.frm-option-row');
            if (row) {
                row.classList.toggle('frm-option-row--empty', !(e.target.value || '').trim());
            }
            showError('');
            ensureTrailing();
            buildHidden();
            updateCount();
        });

        // Remove button
        optionsField.addEventListener('click', (e) => {
            if (!e.target.classList.contains('frm-option-remove')) {
                return;
            }
            const row = e.target.closest('.frm-option-row');
            if (!row) {
                return;
            }
            const inputs = getOptionInputs();
            if (inputs.length <= 1) {
                if (inputs[0]) {
                    inputs[0].value = '';
                }
                buildHidden();
                updateCount();
                return;
            }
            row.remove();
            ensureTrailing();
            buildHidden();
            updateCount();
        });

        // Drag-and-drop reordering
        let dragged = null;
        optionsField.addEventListener('dragstart', (e) => {
            dragged = e.target.closest('.frm-option-row');
            if (dragged) {
                dragged.classList.add('is-dragging');
            }
            if (e.dataTransfer) {
                e.dataTransfer.effectAllowed = 'move';
            }
        });
        optionsField.addEventListener('dragend', () => {
            if (dragged) {
                dragged.classList.remove('is-dragging');
            }
            dragged = null;
            ensureTrailing();
            buildHidden();
            updateCount();
        });
        optionsField.addEventListener('dragover', (e) => {
            if (!dragged) {
                return;
            }
            const row = e.target.closest('.frm-option-row');
            if (!row || row === dragged) {
                return;
            }
            e.preventDefault();
            const before = (e.clientY - row.getBoundingClientRect().top) < (row.offsetHeight / 2);
            optionsField.insertBefore(dragged, before ? row : row.nextSibling);
        });
    }

    // Form submit validation
    editorForm.addEventListener('submit', (e) => {
        buildHidden();
        if (!typeSelect) {
            return;
        }
        const t = typeSelect.value.toLowerCase();
        if (t === 'single_choice' || t === 'multi_choice') {
            const hasOpt = getOptionInputs().some((i) => {
                return (i.value || '').trim();
            });
            if (!hasOpt) {
                e.preventDefault();
                showError('Add at least one option before saving.');
                if (optionsWrap) {
                    optionsWrap.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
        }
    });

    // Init
    syncVisible();
    ensureTrailing();
}());
