/* global TSOIMMAHighlight */
(function() {
    'use strict';

    if (!window.TSOIMMAHighlight || !TSOIMMAHighlight.attachmentId) {
        return;
    }

    var MAX_ATTEMPTS = 80;
    var ATTEMPT_MS   = 250;
    var DOM_RETRIES  = 24;
    var DOM_RETRY_MS = 150;
    var attempts     = 0;
    var visualAttempts = 0;
    var needles      = TSOIMMAHighlight.needles || [];
    var attachmentId = String(TSOIMMAHighlight.attachmentId);
    var mode         = TSOIMMAHighlight.mode || 'visual';
    var codeStarted  = false;
    var visualDone   = false;

    function parseAttachmentId() {
        return parseInt(attachmentId, 10);
    }

    function switchEditorMode(targetMode) {
        if (!window.wp || !wp.data || !wp.data.dispatch) {
            return false;
        }
        try {
            wp.data.dispatch('core/edit-post').switchEditorMode('visual' === targetMode ? 'visual' : 'text');
            return true;
        } catch (e) {
            return false;
        }
    }

    function getBlockEditorStore() {
        if (!window.wp || !wp.data) {
            return null;
        }
        try {
            var select = wp.data.select('core/block-editor');
            var dispatch = wp.data.dispatch('core/block-editor');
            if (select && dispatch && typeof select.getBlocks === 'function') {
                return { select: select, dispatch: dispatch };
            }
        } catch (e) {
            return null;
        }
        return null;
    }

    function getEditorCanvas() {
        return document.querySelector('.edit-post-visual-editor')
            || document.querySelector('.block-editor-writing-flow')
            || document.querySelector('.interface-interface-skeleton__content')
            || document;
    }

    function idMatches(value, id) {
        return parseInt(value, 10) === id;
    }

    function idsArrayContains(list, id) {
        if (!Array.isArray(list)) {
            return false;
        }
        for (var i = 0; i < list.length; i++) {
            if (idMatches(list[i], id)) {
                return true;
            }
        }
        return false;
    }

    function blockContainsAttachment(block, id) {
        var attrs = block.attributes || {};

        if (idMatches(attrs.id, id) || idMatches(attrs.mediaId, id)) {
            return true;
        }
        if (idsArrayContains(attrs.ids, id)) {
            return true;
        }
        if (Array.isArray(attrs.images)) {
            for (var i = 0; i < attrs.images.length; i++) {
                if (attrs.images[i] && idMatches(attrs.images[i].id, id)) {
                    return true;
                }
            }
        }

        if (block.innerBlocks && block.innerBlocks.length) {
            for (var j = 0; j < block.innerBlocks.length; j++) {
                if (blockContainsAttachment(block.innerBlocks[j], id)) {
                    return true;
                }
            }
        }

        return false;
    }

    function findBlockClientIdByAttachmentId(blocks, id) {
        var i;
        var block;
        var inner;

        for (i = 0; i < blocks.length; i++) {
            block = blocks[i];

            if (block.innerBlocks && block.innerBlocks.length) {
                inner = findBlockClientIdByAttachmentId(block.innerBlocks, id);
                if (inner) {
                    return inner;
                }
            }

            if (blockContainsAttachment(block, id)) {
                return block.clientId;
            }
        }

        return null;
    }

    function isGalleryBlockName(name) {
        return name === 'core/gallery'
            || name === 'jetpack/tiled-gallery'
            || name === 'jetpack/slideshow'
            || (name && name.indexOf('gallery') !== -1);
    }

    function findScrollTargetClientId(clientId) {
        var store = getBlockEditorStore();
        if (!store || !clientId || typeof store.select.getBlockParents !== 'function') {
            return clientId;
        }

        var parents = store.select.getBlockParents(clientId) || [];
        var i;
        var block;

        for (i = parents.length - 1; i >= 0; i--) {
            block = store.select.getBlock(parents[i]);
            if (block && isGalleryBlockName(block.name)) {
                return parents[i];
            }
        }

        return clientId;
    }

    function findMediaElement(root) {
        if (!root || !root.querySelector) {
            return null;
        }

        return root.querySelector('[data-id="' + attachmentId + '"]')
            || root.querySelector('figure[data-id="' + attachmentId + '"]')
            || root.querySelector('.wp-image-' + attachmentId)
            || root.querySelector('img[class*="-' + attachmentId + '"]')
            || root.querySelector('img[src*="wp-image-' + attachmentId + '"]');
    }

    function scrollBlockIntoView(clientId) {
        var store = getBlockEditorStore();
        var blockEl;
        var canvas;
        var scrollParent;

        if (store && store.dispatch && typeof store.dispatch.scrollToBlock === 'function') {
            store.dispatch.scrollToBlock(clientId, 'center');
        }

        blockEl = document.querySelector('[data-block="' + clientId + '"]');
        if (blockEl) {
            blockEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return true;
        }

        canvas = getEditorCanvas();
        scrollParent = canvas.closest('.interface-interface-skeleton__content') || canvas;
        if (scrollParent && scrollParent.scrollHeight > scrollParent.clientHeight) {
            scrollParent.scrollTop = Math.max(0, scrollParent.scrollHeight / 2);
        }

        return false;
    }

    function applyDomHighlight(clientId, scrollClientId) {
        var blockEl = document.querySelector('[data-block="' + clientId + '"]')
            || document.querySelector('[data-block="' + scrollClientId + '"]');
        var canvas = getEditorCanvas();
        var mediaEl = findMediaElement(blockEl) || findMediaElement(canvas);
        var scrollTarget = blockEl || mediaEl;

        if (!scrollTarget && !mediaEl) {
            return false;
        }

        if (scrollTarget) {
            scrollTarget.scrollIntoView({ behavior: 'smooth', block: 'center' });
            scrollTarget.classList.add('tsoimma-highlight-block');
        }

        if (mediaEl) {
            mediaEl.classList.add('tsoimma-highlight-media');
            mediaEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        setTimeout(function() {
            if (scrollTarget) {
                scrollTarget.classList.remove('tsoimma-highlight-block');
            }
            if (mediaEl) {
                mediaEl.classList.remove('tsoimma-highlight-media');
            }
        }, 8000);

        return true;
    }

    function finishVisualHighlight(clientId, scrollClientId, domAttempt) {
        if (visualDone) {
            return;
        }

        if (applyDomHighlight(clientId, scrollClientId)) {
            visualDone = true;
            flashBanner(
                TSOIMMAHighlight.visualLabel
                    || TSOIMMAHighlight.label
                    || ('Attachment #' + attachmentId)
            );
            return;
        }

        if (domAttempt < DOM_RETRIES) {
            setTimeout(function() {
                finishVisualHighlight(clientId, scrollClientId, domAttempt + 1);
            }, DOM_RETRY_MS);
            return;
        }

        if (mode !== 'code') {
            bootCodeEditor();
        }
    }

    function applyVisualHighlight(clientId) {
        var store = getBlockEditorStore();
        var scrollClientId;

        if (!store || !clientId) {
            return false;
        }

        switchEditorMode('visual');
        scrollClientId = findScrollTargetClientId(clientId);

        store.dispatch.selectBlock(clientId);
        scrollBlockIntoView(scrollClientId);

        setTimeout(function() {
            store.dispatch.selectBlock(clientId);
            scrollBlockIntoView(scrollClientId);
            finishVisualHighlight(clientId, scrollClientId, 0);
        }, 450);

        return true;
    }

    function flashBanner(message) {
        var existing = document.getElementById('tsoimma-highlight-banner');
        if (existing) {
            existing.remove();
        }
        var banner = document.createElement('div');
        banner.id = 'tsoimma-highlight-banner';
        banner.textContent = message;
        document.body.appendChild(banner);
        setTimeout(function() {
            banner.classList.add('is-visible');
        }, 10);
        setTimeout(function() {
            banner.classList.remove('is-visible');
        }, 6000);
    }

    function findCodeMirror5() {
        var nodes = document.querySelectorAll('.CodeMirror');
        for (var i = 0; i < nodes.length; i++) {
            if (nodes[i].CodeMirror) {
                return nodes[i].CodeMirror;
            }
        }
        return null;
    }

    function findTextarea() {
        var selectors = [
            'textarea.editor-post-text-editor',
            'textarea.block-editor-plain-text',
            '#content',
            '.wp-editor-area',
            'textarea[name="content"]'
        ];
        for (var i = 0; i < selectors.length; i++) {
            var el = document.querySelector(selectors[i]);
            if (el && el.value && el.value.length > 0) {
                return el;
            }
        }
        var textareas = document.querySelectorAll('textarea');
        for (var j = 0; j < textareas.length; j++) {
            if (textareas[j].value && textareas[j].value.indexOf('wp:') !== -1) {
                return textareas[j];
            }
        }
        return null;
    }

    function pickNeedle(text) {
        for (var i = 0; i < needles.length; i++) {
            if (text.indexOf(needles[i]) !== -1) {
                return needles[i];
            }
        }
        return 'data-id="' + attachmentId + '"';
    }

    function highlightCodeMirror(cm, needle) {
        var cursor = cm.getSearchCursor(needle, null, false);
        if (!cursor.find()) {
            return false;
        }
        cm.setSelection(cursor.from(), cursor.to());
        cm.scrollIntoView({ from: cursor.from(), to: cursor.to() }, 120);
        cm.focus();
        return true;
    }

    function highlightTextarea(textarea, needle) {
        var text = textarea.value || '';
        var index = text.indexOf(needle);
        if (index === -1) {
            return false;
        }

        textarea.focus();
        textarea.setSelectionRange(index, index + needle.length);

        var before = text.slice(0, index);
        var lineIndex = before.split('\n').length - 1;
        var lineHeight = parseInt(window.getComputedStyle(textarea).lineHeight, 10) || 18;
        textarea.scrollTop = Math.max(0, (lineIndex - 3) * lineHeight);

        textarea.classList.add('tsoimma-highlight-target');
        setTimeout(function() {
            textarea.classList.remove('tsoimma-highlight-target');
        }, 5000);

        return true;
    }

    function tryCodeHighlight() {
        attempts += 1;

        var cm = findCodeMirror5();
        var textarea = findTextarea();
        var sourceText = cm ? cm.getValue() : (textarea ? textarea.value : '');

        if (!sourceText) {
            if (attempts < MAX_ATTEMPTS) {
                setTimeout(tryCodeHighlight, ATTEMPT_MS);
            }
            return;
        }

        var needle = pickNeedle(sourceText);
        var ok = false;

        if (cm) {
            ok = highlightCodeMirror(cm, needle);
        }
        if (!ok && textarea) {
            ok = highlightTextarea(textarea, needle);
        }

        if (ok) {
            flashBanner(TSOIMMAHighlight.codeLabel || TSOIMMAHighlight.label || ('Attachment #' + attachmentId));
            return;
        }

        if (attempts < MAX_ATTEMPTS) {
            setTimeout(tryCodeHighlight, ATTEMPT_MS);
        }
    }

    function bootCodeEditor() {
        if (codeStarted) {
            return;
        }
        codeStarted = true;
        switchEditorMode('text');
        setTimeout(tryCodeHighlight, 400);
    }

    function tryVisualHighlight() {
        visualAttempts += 1;

        var store = getBlockEditorStore();
        if (!store) {
            if (visualAttempts < MAX_ATTEMPTS) {
                setTimeout(tryVisualHighlight, ATTEMPT_MS);
                return;
            }
            if (mode !== 'code') {
                bootCodeEditor();
            }
            return;
        }

        if (typeof store.select.isTyping === 'function' && store.select.isTyping()) {
            if (visualAttempts < MAX_ATTEMPTS) {
                setTimeout(tryVisualHighlight, ATTEMPT_MS);
                return;
            }
        }

        var blocks = store.select.getBlocks();
        if (!blocks || !blocks.length) {
            if (visualAttempts < MAX_ATTEMPTS) {
                setTimeout(tryVisualHighlight, ATTEMPT_MS);
                return;
            }
            bootCodeEditor();
            return;
        }

        var clientId = findBlockClientIdByAttachmentId(blocks, parseAttachmentId());
        if (!clientId) {
            if (visualAttempts < MAX_ATTEMPTS) {
                setTimeout(tryVisualHighlight, ATTEMPT_MS);
                return;
            }
            bootCodeEditor();
            return;
        }

        applyVisualHighlight(clientId);
    }

    function boot() {
        if (mode === 'code') {
            bootCodeEditor();
            return;
        }

        tryVisualHighlight();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
