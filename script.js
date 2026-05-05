// assets/js/script.js

document.addEventListener('DOMContentLoaded', function() {
    initializePageNavigation();
    initializeUploadArea();
    initializeTopicManager();
    initializeTopicAdminManager();
    initializeSearch();
    initializeNotificationCenter();
    initializeHelpSearch();
    initializeApprovedFileBounce();
    initializeLogoutReset();
    collapseDashboardTopicsOnLoad();
    restoreUploadState();
});

const PAGE_STATE_KEY = 'helpdeskActivePage';
const TOPIC_SCROLL_TARGET_KEY = 'helpdeskTopicScrollTarget';

window.addEventListener('pageshow', function(event) {
    if (event.persisted) {
        collapseDashboardTopicsOnLoad();
    }
});

function collapseDashboardTopicsOnLoad() {
    const dashboardCards = document.querySelectorAll('#dashboardTopicList .help-topic-card');
    dashboardCards.forEach(card => {
        resetDashboardTopicCardState(card);
    });
}

function collapseDashboardTopicCards() {
    document.querySelectorAll('#dashboardTopicList .topic-template-card').forEach(card => {
        resetDashboardTopicCardState(card);
    });
}

function resetDashboardTopicCardState(card) {
    if (!card) {
        return;
    }

    card.classList.remove('active');
    card.querySelectorAll('.topic-subitem-leaf').forEach(item => item.classList.remove('active'));
    card.querySelectorAll('.topic-subitem-content').forEach(content => content.classList.remove('is-visible'));
    card.querySelectorAll('.topic-subitem-group').forEach(group => group.classList.remove('active'));
    card.querySelectorAll('.topic-subitem-parent').forEach(button => {
        button.classList.remove('active');
        button.setAttribute('aria-expanded', 'false');
    });

    const header = card.querySelector('.topic-template-header');
    if (header) {
        header.setAttribute('aria-expanded', 'false');
    }
}

function showPage(page, options = {}) {
    const { persist = true } = options;
    const navItems = document.querySelectorAll('.nav-item');
    const pageSections = document.querySelectorAll('.page-section');

    navItems.forEach(item => item.classList.remove('active'));
    pageSections.forEach(section => section.classList.remove('active'));

    const pageSection = document.getElementById(page);
    if (pageSection) {
        pageSection.classList.add('active');
    }

    let activeNav = document.querySelector(`.nav-item[data-page="${page}"]`);
    if (!activeNav && page === 'upload') {
        activeNav = document.querySelector('.nav-item[data-page="dashboard"]');
    }
    if (activeNav) {
        activeNav.classList.add('active');
    }

    if (persist) {
        sessionStorage.setItem(PAGE_STATE_KEY, page);
    }
}

function initializePageNavigation() {
    const navItems = document.querySelectorAll('.nav-item');
    const shouldRestorePage = document.body?.dataset?.persistPageState === '1';

    const storedPage = shouldRestorePage ? sessionStorage.getItem(PAGE_STATE_KEY) : null;
    if (storedPage && document.getElementById(storedPage)) {
        showPage(storedPage, { persist: false });
    }

    navItems.forEach(item => {
        item.addEventListener('click', (e) => {
            e.preventDefault();
            showPage(item.dataset.page);
        });
    });
}

function openUploadWithTopic(topic = '') {
    const canUpload = document.body?.dataset?.canUpload !== '0';

    showPage('dashboard');

    const dashboardList = document.getElementById('dashboardTopicList');
    let wasAlreadyVisible = false;
    if (dashboardList) {
        const escapedTopic = topic && typeof CSS !== 'undefined' && typeof CSS.escape === 'function'
            ? CSS.escape(topic)
            : String(topic || '').replace(/"/g, '\\"');
        if (topic) {
            const matchingLeafs = Array.from(dashboardList.querySelectorAll(`.topic-subitem-leaf[data-value="${escapedTopic}"]`));
            const matchingContents = Array.from(dashboardList.querySelectorAll(`.topic-subitem-content[data-topic-path="${escapedTopic}"]`));
            const hasActiveLeaf = matchingLeafs.some(leaf => leaf.classList.contains('active'));
            const hasVisibleContent = matchingContents.some(content => content.classList.contains('is-visible'));
            wasAlreadyVisible = hasActiveLeaf && hasVisibleContent;
        }

        dashboardList.querySelectorAll('.topic-subitem-leaf').forEach(item => item.classList.remove('active'));
        dashboardList.querySelectorAll('.topic-subitem-content').forEach(content => content.classList.remove('is-visible'));
    }

    if (wasAlreadyVisible) {
        if (dashboardList && topic) {
            const escapedTopic = typeof CSS !== 'undefined' && typeof CSS.escape === 'function'
                ? CSS.escape(topic)
                : topic.replace(/"/g, '\\"');
            const matchingLeafs = Array.from(dashboardList.querySelectorAll(`.topic-subitem-leaf[data-value="${escapedTopic}"]`));
            const matchingContents = Array.from(dashboardList.querySelectorAll(`.topic-subitem-content[data-topic-path="${escapedTopic}"]`));
            const leafCard = matchingLeafs.length ? matchingLeafs[0].closest('.topic-template-card') : null;

            matchingLeafs.forEach(leaf => leaf.classList.remove('active'));
            matchingContents.forEach(content => content.classList.remove('is-visible'));
            if (leafCard) {
                resetDashboardTopicCardState(leafCard);
            }

            dashboardList.querySelectorAll('.topic-subitem-group').forEach(group => group.classList.remove('active'));
            dashboardList.querySelectorAll('.topic-subitem-parent').forEach(button => {
                button.classList.remove('active');
                button.setAttribute('aria-expanded', 'false');
            });

            clearDashboardTopicSelection({ clearUploadSelection: true });
        }

        return;
    }

    if (topic) {
        sessionStorage.setItem('helpdeskSelectedTopic', topic);
        updateDashboardUploadBar(topic);
    }

    const matchingCard = topic
        ? Array.from(document.querySelectorAll('#dashboardTopicList .topic-template-card')).find(card => {
            const cardTopic = card.dataset.topic || '';
            return cardTopic === topic || topic.startsWith(cardTopic + ' / ');
        })
        : null;

    if (topic && dashboardList) {
        const escapedTopic = typeof CSS !== 'undefined' && typeof CSS.escape === 'function'
            ? CSS.escape(topic)
            : topic.replace(/"/g, '\\"');
        const matchingLeaf = dashboardList.querySelector(`.topic-subitem-leaf[data-value="${escapedTopic}"]`);

        if (matchingLeaf) {
            matchingLeaf.classList.add('active');
            dashboardList.querySelectorAll(`.topic-subitem-content[data-topic-path="${escapedTopic}"]`).forEach(content => {
                content.classList.add('is-visible');
            });

            const leafCard = matchingLeaf.closest('.topic-template-card');
            if (leafCard) {
                leafCard.classList.add('active');
                const leafHeader = leafCard.querySelector('.topic-template-header');
                if (leafHeader) {
                    leafHeader.setAttribute('aria-expanded', 'true');
                }
            }

            let parentGroup = matchingLeaf.closest('.topic-subitem-group');
            while (parentGroup) {
                parentGroup.classList.add('active');
                const groupButton = parentGroup.querySelector('.topic-subitem-parent');
                if (groupButton) {
                    groupButton.classList.add('active');
                    groupButton.setAttribute('aria-expanded', 'true');
                }
                parentGroup = parentGroup.parentElement ? parentGroup.parentElement.closest('.topic-subitem-group') : null;
            }

            matchingLeaf.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
    }

    if (matchingCard) {
        matchingCard.classList.add('active');
        const header = matchingCard.querySelector('.topic-template-header');
        if (header) {
            header.setAttribute('aria-expanded', 'true');
        }
        matchingCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}

function setUploadUpdateState(mode = '', fileId = '', fileName = '') {
    const resubmitInput = document.getElementById('resubmitFileId');
    const editInput = document.getElementById('editFileId');
    const banner = document.getElementById('resubmitBanner');
    const bannerTitle = document.getElementById('resubmitBannerTitle');
    const bannerText = document.getElementById('resubmitBannerText');
    const submitButton = document.querySelector('#uploadForm button[type="submit"]');
    const cancelUploadButton = document.getElementById('cancelUploadButton');
    const fileInput = document.getElementById('fileInput');

    if (resubmitInput) {
        resubmitInput.value = mode === 'resubmit' && fileId ? String(fileId) : '';
    }

    if (editInput) {
        editInput.value = mode === 'edit' && fileId ? String(fileId) : '';
    }

    if (banner) {
        banner.classList.toggle('active', mode === 'resubmit' || mode === 'edit');
    }

    if (bannerTitle) {
        bannerTitle.textContent = mode === 'edit' ? 'Editing file' : 'Resubmitting file';
    }

    if (bannerText) {
        const safeFileName = fileName || 'this file';
        bannerText.textContent = mode === 'edit'
            ? `Upload the updated version of ${safeFileName}.`
            : `Upload the corrected version of ${safeFileName} for admin review.`;
    }

    if (submitButton) {
        submitButton.textContent = mode === 'edit' ? '✏️ Save File Changes' : mode === 'resubmit' ? '🔁 Resubmit File' : '📤 Upload File';
    }

    if (cancelUploadButton) {
        cancelUploadButton.hidden = !(mode || (fileInput && fileInput.files && fileInput.files.length));
    }
}

function openResubmitUpload(fileId, topic = '', fileName = '') {
    if (typeof window.openUserUploadModalWithTopic === 'function') {
        if (typeof window.configureUserUploadModalMode === 'function') {
            window.configureUserUploadModalMode('resubmit', fileId, fileName);
        }
        window.openUserUploadModalWithTopic(topic || '');
        return;
    }

    showPage('upload');
    setUploadUpdateState('resubmit', fileId, fileName);

    if (topic) {
        selectTopicTemplate(topic, false);
    }

    const fileInput = document.getElementById('fileInput');
    if (fileInput) {
        fileInput.value = '';
        fileInput.dispatchEvent(new Event('change'));
    }

    const uploadArea = document.getElementById('uploadArea');
    if (uploadArea) {
        uploadArea.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}

function openEditUpload(fileId, topic = '', fileName = '') {
    if (typeof window.openUserUploadModalWithTopic === 'function') {
        if (typeof window.configureUserUploadModalMode === 'function') {
            window.configureUserUploadModalMode('edit', fileId, fileName);
        }
        window.openUserUploadModalWithTopic(topic || '');
        return;
    }

    showPage('upload');
    setUploadUpdateState('edit', fileId, fileName);

    if (topic) {
        selectTopicTemplate(topic, false);
    }

    const fileInput = document.getElementById('fileInput');
    if (fileInput) {
        fileInput.value = '';
        fileInput.dispatchEvent(new Event('change'));
    }

    const uploadArea = document.getElementById('uploadArea');
    if (uploadArea) {
        uploadArea.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}

function clearResubmitState() {
    setUploadUpdateState('', '', '');
}

function restoreUploadState() {
    const shouldRestoreUploadState = document.body?.dataset?.restoreUploadState === '1';
    const pendingPage = sessionStorage.getItem('helpdeskNextPage');
    const savedTopic = sessionStorage.getItem('helpdeskSelectedTopic');

    if (!shouldRestoreUploadState) {
        sessionStorage.removeItem('helpdeskNextPage');
        sessionStorage.removeItem('helpdeskSelectedTopic');
        return;
    }

    if (pendingPage) {
        const resolvedPage = pendingPage === 'upload' ? 'dashboard' : pendingPage;
        showPage(resolvedPage);
        sessionStorage.removeItem('helpdeskNextPage');
    }

    if (pendingPage === 'upload' && savedTopic && typeof window.openUserUploadModalWithTopic === 'function') {
        setTimeout(() => {
            window.openUserUploadModalWithTopic(savedTopic);
            sessionStorage.removeItem('helpdeskSelectedTopic');
        }, 80);
    } else if (!pendingPage && savedTopic) {
        // Login/fresh visit: do not auto-open a previously selected topic.
        sessionStorage.removeItem('helpdeskSelectedTopic');
    }
}

function initializeLogoutReset() {
    document.querySelectorAll('.btn-logout').forEach(link => {
        link.addEventListener('click', () => {
            sessionStorage.removeItem(PAGE_STATE_KEY);
            sessionStorage.removeItem('helpdeskNextPage');
            sessionStorage.removeItem('helpdeskSelectedTopic');
        });
    });
}

function triggerHelpSearch() {
    if (typeof window.runHelpCenterSearch === 'function') {
        window.runHelpCenterSearch(true);
    }

    const searchInput = document.getElementById('dashboardTopicSearch');
    if (searchInput) {
        searchInput.focus();
    }
}

function triggerAdminSearch() {
    if (typeof window.runAdminSearch === 'function') {
        window.runAdminSearch(true);
    }

    const searchInput = document.getElementById('adminGlobalSearch');
    if (searchInput) {
        searchInput.focus();
    }
}

function initializeApprovedFileBounce() {
    const triggerBounce = (approvedFileEntry) => {
        if (!approvedFileEntry) {
            return;
        }

        approvedFileEntry.classList.remove('is-bouncing');
        void approvedFileEntry.offsetWidth;
        approvedFileEntry.classList.add('is-bouncing');

        window.setTimeout(() => {
            approvedFileEntry.classList.remove('is-bouncing');
        }, 190);
    };

    document.addEventListener('pointerdown', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        if (!target) {
            return;
        }

        const approvedFileEntry = target.closest('.approved-file-entry');
        if (!approvedFileEntry) {
            return;
        }

        triggerBounce(approvedFileEntry);
    });
}

function initializeActionButtonZoom() {
    const triggerZoom = (button) => {
        if (!button) {
            return;
        }

        if (button.__pressAnimation && typeof button.__pressAnimation.cancel === 'function') {
            button.__pressAnimation.cancel();
        }

        if (typeof button.animate === 'function') {
            button.__pressAnimation = button.animate(
                [
                    { transform: 'scale(1)', boxShadow: '0 0 0 rgba(28, 78, 161, 0)' },
                    { transform: 'scale(0.8)', boxShadow: '0 4px 10px rgba(28, 78, 161, 0.10)', offset: 0.35 },
                    { transform: 'scale(1.1)', boxShadow: '0 14px 28px rgba(28, 78, 161, 0.20)', offset: 0.72 },
                    { transform: 'scale(1)', boxShadow: '0 0 0 rgba(28, 78, 161, 0)' }
                ],
                {
                    duration: 340,
                    easing: 'cubic-bezier(0.22, 1, 0.36, 1)',
                    fill: 'none'
                }
            );
            return;
        }

        button.classList.remove('is-pressing');
        void button.offsetWidth;
        button.classList.add('is-pressing');
    };

    const isZoomTarget = (element) => {
        if (!(element instanceof Element)) {
            return false;
        }

        if (element.closest('input[type="text"], input[type="email"], input[type="password"], input[type="search"], input[type="file"], textarea, select, label')) {
            return false;
        }

        if (element.closest('.topic-template-header, .topic-subitem, .topic-tree-toggle, .topic-tree-remove')) {
            return false;
        }

        const target = element.closest('a, button, input[type="submit"], input[type="button"], [role="button"]');
        if (!target) {
            return false;
        }

        if (target.hasAttribute('disabled') || target.getAttribute('aria-disabled') === 'true') {
            return false;
        }

        return true;
    };

    document.addEventListener('pointerdown', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        if (!target) {
            return;
        }

        if (!isZoomTarget(target)) {
            return;
        }

        const actionButton = target.closest('a, button, input[type="submit"], input[type="button"], [role="button"]');
        triggerZoom(actionButton);
    });

    document.addEventListener('animationend', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        if (!target || !target.classList.contains('is-pressing')) {
            return;
        }

        target.classList.remove('is-pressing');
    });
}

function initializeHelpSearch() {
    const searchInput = document.getElementById('dashboardTopicSearch');
    const dashboardList = document.getElementById('dashboardTopicList');
    const fileSearchInput = document.getElementById('searchFiles');
    const searchShell = document.querySelector('.topbar-search-shell');
    const canUpload = document.body?.dataset?.canUpload !== '0';

    if (!searchInput || !dashboardList) return;

    const normalizeSearchText = (value) => String(value || '')
        .toLowerCase()
        .replace(/&/g, ' and ')
        .replace(/[^a-z0-9\s]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();

    const matchesSearch = (haystack, normalizedTerm) => {
        if (!normalizedTerm) {
            return true;
        }

        const normalizedHaystack = normalizeSearchText(haystack);
        if (normalizedHaystack.includes(normalizedTerm)) {
            return true;
        }

        const tokens = normalizedTerm.split(' ').filter(Boolean);
        return tokens.length > 0 && tokens.every(token => normalizedHaystack.includes(token));
    };

    const applySearch = (searchTerm, shouldOpenFirstResult = false) => {
        const normalizedTerm = normalizeSearchText(searchTerm);
        let firstMatchedFileEntry = null;
        let firstMatchedFeedbackCard = null;
        let firstMatchedSubitem = null;
        let firstMatchedCard = null;

        const expandMatchedPath = (card, topicPath) => {
            if (!card || !topicPath) {
                return;
            }

            const escapedPath = typeof CSS !== 'undefined' && typeof CSS.escape === 'function'
                ? CSS.escape(topicPath)
                : topicPath.replace(/"/g, '\\"');

            const leaf = card.querySelector(`.topic-subitem-leaf[data-value="${escapedPath}"]`);
            if (leaf) {
                leaf.classList.add('active');
                let parentGroup = leaf.closest('.topic-subitem-group');
                while (parentGroup) {
                    parentGroup.classList.add('active');
                    const groupButton = parentGroup.querySelector('.topic-subitem-parent');
                    if (groupButton) {
                        groupButton.classList.add('active');
                        groupButton.setAttribute('aria-expanded', 'true');
                    }
                    parentGroup = parentGroup.parentElement ? parentGroup.parentElement.closest('.topic-subitem-group') : null;
                }
            }

            card.querySelectorAll(`.topic-subitem-content[data-topic-path="${escapedPath}"]`).forEach(content => {
                content.classList.add('is-visible');
            });
        };

        dashboardList.querySelectorAll('.topic-template-card').forEach(card => {
            const title = normalizeSearchText(card.dataset.topic || '');
            const subitems = Array.from(card.querySelectorAll('.topic-subitem[data-value]'));
            const fileEntries = Array.from(card.querySelectorAll('.approved-file-entry'));
            const feedbackCards = Array.from(card.querySelectorAll('.upload-feedback-card'));
            const matchedPaths = new Set();
            const titleMatches = matchesSearch(title, normalizedTerm);
            let hasMatch = titleMatches;

            card.querySelectorAll('.topic-subitem-leaf').forEach(leaf => leaf.classList.remove('active'));
            card.querySelectorAll('.topic-subitem-content').forEach(content => content.classList.remove('is-visible'));
            card.querySelectorAll('.topic-subitem-group').forEach(group => group.classList.remove('active'));
            card.querySelectorAll('.topic-subitem-parent').forEach(button => {
                button.classList.remove('active');
                button.setAttribute('aria-expanded', 'false');
            });

            subitems.forEach(item => {
                const itemText = normalizeSearchText(item.textContent);
                const itemPathValue = normalizeSearchText(item.dataset?.value || '');
                const itemMatches = matchesSearch(itemText, normalizedTerm) || matchesSearch(itemPathValue, normalizedTerm);
                item.style.display = itemMatches || titleMatches ? '' : 'none';
                if (itemMatches) {
                    hasMatch = true;
                    if (item.dataset && item.dataset.value) {
                        matchedPaths.add(item.dataset.value);
                    }
                    if (!firstMatchedSubitem) {
                        firstMatchedSubitem = item;
                    }
                }
            });

            fileEntries.forEach(entry => {
                const entryText = normalizeSearchText(entry.textContent);
                const entryMatches = matchesSearch(entryText, normalizedTerm) || titleMatches;
                entry.style.display = entryMatches ? '' : 'none';
                if (entryMatches) {
                    hasMatch = true;
                    const entryPath = entry.closest('.topic-subitem-content')?.dataset?.topicPath;
                    if (entryPath) {
                        matchedPaths.add(entryPath);
                    }
                    if (!firstMatchedFileEntry) {
                        firstMatchedFileEntry = entry;
                    }
                }
            });

            feedbackCards.forEach(cardItem => {
                const feedbackText = normalizeSearchText(cardItem.textContent);
                const feedbackMatches = matchesSearch(feedbackText, normalizedTerm) || titleMatches;
                cardItem.style.display = feedbackMatches ? '' : 'none';
                if (feedbackMatches) {
                    hasMatch = true;
                    const feedbackPath = cardItem.closest('.topic-subitem-content')?.dataset?.topicPath;
                    if (feedbackPath) {
                        matchedPaths.add(feedbackPath);
                    }
                    if (!firstMatchedFeedbackCard) {
                        firstMatchedFeedbackCard = cardItem;
                    }
                }
            });

            if (normalizedTerm !== '') {
                matchedPaths.forEach(path => expandMatchedPath(card, path));
            }

            card.style.display = hasMatch ? '' : 'none';
            card.classList.toggle('active', normalizedTerm !== '' && hasMatch);
            const header = card.querySelector('.topic-template-header');
            if (header) {
                header.setAttribute('aria-expanded', normalizedTerm !== '' && hasMatch ? 'true' : 'false');
            }

            if (hasMatch && !firstMatchedCard && titleMatches) {
                firstMatchedCard = card;
            }
        });

        if (fileSearchInput) {
            fileSearchInput.value = searchTerm;
            fileSearchInput.dispatchEvent(new Event('keyup'));
        }

        if (!shouldOpenFirstResult) {
            return;
        }

        if (normalizedTerm === '') {
            showPage('dashboard');
            return;
        }

        if (firstMatchedFileEntry) {
            showPage('dashboard');
            firstMatchedFileEntry.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        if (firstMatchedFeedbackCard) {
            showPage('dashboard');
            firstMatchedFeedbackCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        if (firstMatchedSubitem?.dataset.value) {
            openUploadWithTopic(firstMatchedSubitem.dataset.value);
            return;
        }

        if (firstMatchedCard?.dataset.topic) {
            openUploadWithTopic(firstMatchedCard.dataset.topic);
            return;
        }

        const visibleFileRow = document.querySelector('#filesTable tbody tr:not([style*="display: none"])');
        if (visibleFileRow) {
            showPage('myfiles');
            visibleFileRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        alert('No matching result found.');
    };

    window.runHelpCenterSearch = (shouldOpenFirstResult = false) => {
        applySearch(searchInput.value, shouldOpenFirstResult);
    };

    if (searchShell) {
        searchShell.addEventListener('click', (event) => {
            if (!event.target.closest('.search-trigger')) {
                searchInput.focus();
            }
        });
    }

    searchInput.addEventListener('input', () => applySearch(searchInput.value, false));
    searchInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            applySearch(searchInput.value, true);
        }
    });
}

function toggleNestedTopicBranch(button) {
    const group = button.closest('.topic-subitem-group');
    if (!group) return;

    const isOpen = group.classList.contains('active');
    const parentContainer = group.parentElement;

    if (parentContainer) {
        Array.from(parentContainer.children).forEach(sibling => {
            if (sibling !== group && sibling.classList && sibling.classList.contains('topic-subitem-group')) {
                sibling.classList.remove('active');
                const siblingButton = sibling.querySelector('.topic-subitem-parent');
                if (siblingButton) {
                    siblingButton.classList.remove('active');
                    siblingButton.setAttribute('aria-expanded', 'false');
                }
                sibling.querySelectorAll('.topic-subitem-group').forEach(child => child.classList.remove('active'));
                sibling.querySelectorAll('.topic-subitem-parent').forEach(childButton => {
                    childButton.classList.remove('active');
                    childButton.setAttribute('aria-expanded', 'false');
                });
                sibling.querySelectorAll('.topic-subitem-leaf').forEach(leaf => leaf.classList.remove('active'));
                sibling.querySelectorAll('.topic-subitem-content').forEach(content => content.classList.remove('is-visible'));
            }
        });
    }

    group.classList.toggle('active', !isOpen);
    button.classList.toggle('active', !isOpen);
    button.setAttribute('aria-expanded', isOpen ? 'false' : 'true');

    if (isOpen) {
        group.querySelectorAll('.topic-subitem-group').forEach(child => child.classList.remove('active'));
        group.querySelectorAll('.topic-subitem-parent').forEach(childButton => {
            childButton.classList.remove('active');
            childButton.setAttribute('aria-expanded', 'false');
        });
        group.querySelectorAll('.topic-subitem-leaf').forEach(leaf => leaf.classList.remove('active'));
        group.querySelectorAll('.topic-subitem-content').forEach(content => content.classList.remove('is-visible'));

        if (group.closest('#dashboardTopicList')) {
            clearDashboardTopicSelection({ clearUploadSelection: true });
        }
    }
}

function updateTopicLocationLabels(topic = '') {
    const mainTopicLabel = document.getElementById('mainTopicLabel');
    const sectionTopicLabel = document.getElementById('sectionTopicLabel');
    const underSectionTopicLabel = document.getElementById('underSectionTopicLabel');

    if (!mainTopicLabel && !sectionTopicLabel && !underSectionTopicLabel) {
        return;
    }

    const parts = String(topic || '')
        .split(' / ')
        .map(part => part.trim())
        .filter(Boolean);

    const mainTopic = parts[0] || 'Not selected';
    const sectionTopic = parts[1] || 'Not selected';
    const underSectionTopic = parts.length > 2 ? parts.slice(2).join(' / ') : 'Not selected';

    if (mainTopicLabel) {
        mainTopicLabel.textContent = mainTopic;
    }

    if (sectionTopicLabel) {
        sectionTopicLabel.textContent = sectionTopic;
    }

    if (underSectionTopicLabel) {
        underSectionTopicLabel.textContent = underSectionTopic;
    }
}

function updateDashboardUploadBar(topic = '') {
    const mainTopicLabel = document.getElementById('dashboardMainTopicLabel');
    const sectionTopicLabel = document.getElementById('dashboardSectionTopicLabel');
    const underSectionTopicLabel = document.getElementById('dashboardUnderSectionTopicLabel');
    const sectionTopicPill = document.getElementById('dashboardSectionTopicPill');
    const underSectionTopicPill = document.getElementById('dashboardUnderSectionTopicPill');
    const uploadBtn = document.getElementById('dashboardUploadBtn');

    if (!mainTopicLabel && !sectionTopicLabel && !underSectionTopicLabel && !uploadBtn) {
        return;
    }

    const parts = String(topic || '')
        .split(' / ')
        .map(part => part.trim())
        .filter(Boolean);

    const mainTopic = parts[0] || 'Not selected';
    const sectionTopic = parts[1] || 'Not selected';
    const underSectionTopic = parts.length > 2 ? parts.slice(2).join(' / ') : 'Not selected';

    if (mainTopicLabel) {
        mainTopicLabel.textContent = mainTopic;
    }
    if (sectionTopicLabel) {
        sectionTopicLabel.textContent = sectionTopic;
    }
    if (underSectionTopicLabel) {
        underSectionTopicLabel.textContent = underSectionTopic;
    }
    if (sectionTopicPill) {
        sectionTopicPill.hidden = parts.length < 2;
    }
    if (underSectionTopicPill) {
        underSectionTopicPill.hidden = parts.length < 3;
    }
    if (uploadBtn) {
        uploadBtn.dataset.topic = topic || '';
    }
}

function clearDashboardTopicSelection(options = {}) {
    const { clearUploadSelection = false } = options;

    sessionStorage.removeItem('helpdeskSelectedTopic');
    sessionStorage.removeItem('helpdeskNextPage');
    updateDashboardUploadBar('');
    updateTopicLocationLabels('');

    const topicSelect = document.getElementById('topicSelect');
    if (topicSelect) {
        topicSelect.value = '';
    }

    const selectedTopicLabel = document.getElementById('selectedTopicLabel');
    if (selectedTopicLabel) {
        selectedTopicLabel.textContent = 'None';
    }

    const uploadArea = document.getElementById('uploadArea');
    if (uploadArea) {
        uploadArea.classList.remove('topic-selected');
    }

    if (!clearUploadSelection) {
        return;
    }

    const fileInput = document.getElementById('fileInput');
    if (fileInput) {
        fileInput.value = '';
        fileInput.dispatchEvent(new Event('change'));
    }

    clearResubmitState();
}

function openUploadPage(topic = '') {
    const canUpload = document.body?.dataset?.canUpload !== '0';
    if (!canUpload) {
        return;
    }

    if (typeof window.openUserUploadModalWithTopic === 'function') {
        if (typeof window.configureUserUploadModalMode === 'function') {
            window.configureUserUploadModalMode('', '', '');
        }
        window.openUserUploadModalWithTopic(topic || '');
        return;
    }

    collapseDashboardTopicCards();
    clearResubmitState();

    if (topic) {
        sessionStorage.setItem('helpdeskSelectedTopic', topic);
    } else {
        sessionStorage.removeItem('helpdeskSelectedTopic');
    }
    sessionStorage.setItem('helpdeskNextPage', 'upload');

    showPage('upload');

    setTimeout(() => {
        if (topic) {
            selectTopicTemplate(topic, false);
        }

        const uploadArea = document.getElementById('uploadArea');
        if (uploadArea) {
            uploadArea.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }, 60);
}

function openUploadPageFromDashboard() {
    const selectedTopic = sessionStorage.getItem('helpdeskSelectedTopic') || '';
    openUploadPage(selectedTopic);
}

function initializeTopicManager() {
    const topicSelect = document.getElementById('topicSelect');
    const selectedTopicLabel = document.getElementById('selectedTopicLabel');
    const topicCards = document.querySelectorAll('.topic-template-card');
    const uploadArea = document.getElementById('uploadArea');

    if (!topicSelect || !topicCards.length) return;

    const currentTopic = topicSelect.value || '';
    if (selectedTopicLabel) {
        selectedTopicLabel.textContent = currentTopic || 'None';
    }
    updateTopicLocationLabels(currentTopic);
    updateDashboardUploadBar(currentTopic);

    topicCards.forEach(card => {
        const isMatch = !!currentTopic && (currentTopic === card.dataset.topic || currentTopic.startsWith(card.dataset.topic + ' / '));
        card.classList.remove('active');
        card.querySelectorAll('.topic-subitem-group').forEach(group => group.classList.remove('active'));
        card.querySelectorAll('.topic-subitem-parent').forEach(button => button.setAttribute('aria-expanded', 'false'));
        const header = card.querySelector('.topic-template-header');
        if (header) {
            header.setAttribute('aria-expanded', 'false');
        }
        card.querySelectorAll('.topic-subitem').forEach(item => {
            item.classList.toggle('active', item.dataset.value === currentTopic);
        });
    });

    if (uploadArea) {
        uploadArea.classList.toggle('topic-selected', !!currentTopic);
    }

}

function toggleTopicTemplate(button, topic) {
    const card = button.closest('.topic-template-card');
    if (!card) return;
    const isDashboardCard = !!card.closest('#dashboardTopicList');

    const subitems = card.querySelectorAll('.topic-subitem');
    if (!subitems.length && !isDashboardCard) {
        selectTopicTemplate(topic);
        button.setAttribute('aria-expanded', 'false');
        card.classList.remove('active');
        return;
    }

    const alreadyOpen = card.classList.contains('active');

    document.querySelectorAll('.topic-template-card').forEach(item => {
        if (item !== card) {
            if (item.closest('#dashboardTopicList')) {
                resetDashboardTopicCardState(item);
            } else {
                item.classList.remove('active');
                const header = item.querySelector('.topic-template-header');
                if (header) {
                    header.setAttribute('aria-expanded', 'false');
                }
            }
        }
    });

    if (alreadyOpen) {
        if (isDashboardCard) {
            resetDashboardTopicCardState(card);
            clearDashboardTopicSelection({ clearUploadSelection: true });
            return;
        }

        card.classList.remove('active');
        button.setAttribute('aria-expanded', 'false');
        return;
    }

    card.classList.add('active');
    button.setAttribute('aria-expanded', 'true');

    if (isDashboardCard) {
        card.querySelectorAll('.topic-subitem-leaf').forEach(leaf => leaf.classList.remove('active'));
        card.querySelectorAll('.topic-subitem-content').forEach(content => content.classList.remove('is-visible'));

        // Keep topic interaction read-only; upload modal opens only from the main Upload File button.
    }
}

function selectTopicTemplate(topic, shouldScroll = true) {
    const topicSelect = document.getElementById('topicSelect');
    const selectedTopicLabel = document.getElementById('selectedTopicLabel');
    const uploadArea = document.getElementById('uploadArea');

    if (topic) {
        sessionStorage.setItem('helpdeskSelectedTopic', topic);
    } else {
        sessionStorage.removeItem('helpdeskSelectedTopic');
    }

    if (topicSelect) {
        topicSelect.value = topic || '';
    }

    if (selectedTopicLabel) {
        selectedTopicLabel.textContent = topic || 'None';
    }
    updateTopicLocationLabels(topic || '');
    updateDashboardUploadBar(topic || '');

    document.querySelectorAll('.topic-template-card').forEach(card => {
        card.classList.remove('active');
        card.querySelectorAll('.topic-subitem-group').forEach(group => group.classList.remove('active'));
        card.querySelectorAll('.topic-subitem-parent').forEach(button => button.setAttribute('aria-expanded', 'false'));
        const header = card.querySelector('.topic-template-header');
        if (header) {
            header.setAttribute('aria-expanded', 'false');
        }
        card.querySelectorAll('.topic-subitem').forEach(item => {
            item.classList.toggle('active', item.dataset.value === topic);
        });
    });

    if (uploadArea) {
        uploadArea.classList.toggle('topic-selected', !!topic);
        if (topic && shouldScroll) {
            uploadArea.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

}

function initializeUploadArea() {
    const uploadArea = document.getElementById('uploadArea');
    const uploadPreview = document.getElementById('uploadPreview');
    const fileInput = document.getElementById('fileInput');
    const uploadForm = document.getElementById('uploadForm');
    const cancelUploadButton = document.getElementById('cancelUploadButton');
    const topicSelect = document.getElementById('topicSelect');
    const selectedTopicLabel = document.getElementById('selectedTopicLabel');
    const mainTopicSelect = document.getElementById('mainTopicSelect');
    const sectionSelect = document.getElementById('sectionSelect');
    const underSectionSelect = document.getElementById('underSectionSelect');

    if (!uploadArea || !uploadPreview || !fileInput || !uploadForm) return;

    const submitButton = uploadForm.querySelector('button[type="submit"]');
    const resubmitInput = document.getElementById('resubmitFileId');
    const editInput = document.getElementById('editFileId');
    let selectedFiles = [];
    let isUploading = false;

    const updateSelectedTopicLabel = () => {
        if (selectedTopicLabel && topicSelect) {
            selectedTopicLabel.textContent = topicSelect.value || 'None';
        }
        if (topicSelect) {
            updateTopicLocationLabels(topicSelect.value || '');
            updateDashboardUploadBar(topicSelect.value || '');
        }
    };

    const resolveSelectedTopic = () => {
        const hiddenTopic = topicSelect ? topicSelect.value.trim() : '';
        if (hiddenTopic) {
            return hiddenTopic;
        }

        const mainTopic = mainTopicSelect ? mainTopicSelect.value.trim() : '';
        const sectionTopic = sectionSelect ? sectionSelect.value.trim() : '';
        const underSectionTopic = underSectionSelect ? underSectionSelect.value.trim() : '';

        if (mainTopic && sectionTopic && underSectionTopic) {
            return `${mainTopic} / ${sectionTopic} / ${underSectionTopic}`;
        }

        if (mainTopic && sectionTopic) {
            return `${mainTopic} / ${sectionTopic}`;
        }

        return mainTopic;
    };

    const updateUploadPreview = () => {
        if (!uploadPreview) return;

        if (selectedFiles.length > 0) {
            uploadArea.classList.add('has-files');
            const fileItems = selectedFiles.map(file => `
                <li class="upload-file-item">
                    <span class="upload-file-name">${file.name}</span>
                    <span class="upload-file-size">${(file.size / 1024).toFixed(1)} KB</span>
                </li>
            `).join('');

            uploadPreview.innerHTML = `
                <div class="upload-summary">${selectedFiles.length} file(s) selected</div>
                <ul class="upload-file-list">${fileItems}</ul>
                <p class="upload-change-hint">Click again if you want to change the files.</p>
            `;
        } else {
            uploadArea.classList.remove('has-files');
            uploadPreview.innerHTML = `
                <svg class="upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                    <polyline points="17 8 12 3 7 8"></polyline>
                    <line x1="12" y1="3" x2="12" y2="15"></line>
                </svg>
                <h3>Drag & Drop Files Here</h3>
                <p>or click to browse</p>
            `;
        }

        if (cancelUploadButton) {
            cancelUploadButton.hidden = !(selectedFiles.length > 0 || (resubmitInput && resubmitInput.value) || (editInput && editInput.value));
        }
    };

    const resetUploadSelection = ({ clearMode = false } = {}) => {
        selectedFiles = [];
        fileInput.value = '';
        updateUploadPreview();

        if (clearMode) {
            clearResubmitState();
        }
    };

    const setSelectedFiles = (files) => {
        selectedFiles = Array.from(files || []);
        updateUploadPreview();
    };

    const setUploadingState = (uploading) => {
        isUploading = uploading;

        if (submitButton) {
            const isResubmit = !!(resubmitInput && resubmitInput.value);
            const isEdit = !!(editInput && editInput.value);
            submitButton.disabled = uploading;
            submitButton.textContent = uploading
                ? (isEdit ? 'Saving...' : isResubmit ? 'Resubmitting...' : 'Uploading...')
                : (isEdit ? '✏️ Save File Changes' : isResubmit ? '🔁 Resubmit File' : '📤 Upload File');
        }

        if (uploadArea) {
            uploadArea.style.pointerEvents = uploading ? 'none' : '';
            uploadArea.style.opacity = uploading ? '0.72' : '';
        }

        if (cancelUploadButton) {
            cancelUploadButton.disabled = uploading;
        }
    };

    const syncInputFiles = (files) => {
        try {
            const transfer = new DataTransfer();
            Array.from(files || []).forEach(file => transfer.items.add(file));
            fileInput.files = transfer.files;
        } catch (error) {
            // Browser may block programmatic assignment; selectedFiles is still used for upload.
        }
    };

    if (topicSelect) {
        topicSelect.addEventListener('change', updateSelectedTopicLabel);
        updateSelectedTopicLabel();
    }

    setUploadingState(false);

    updateUploadPreview();

    if (cancelUploadButton) {
        cancelUploadButton.addEventListener('click', () => {
            resetUploadSelection({ clearMode: true });
        });
    }

    uploadArea.addEventListener('click', () => {
        fileInput.click();
    });

    uploadArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadArea.classList.add('dragover');
    });

    uploadArea.addEventListener('dragleave', () => {
        uploadArea.classList.remove('dragover');
    });

    uploadArea.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadArea.classList.remove('dragover');

        if (e.dataTransfer?.files?.length) {
            syncInputFiles(e.dataTransfer.files);
            setSelectedFiles(e.dataTransfer.files);
        }
    });

    fileInput.addEventListener('change', () => {
        if (fileInput.files?.length) {
            setSelectedFiles(fileInput.files);
        } else {
            setSelectedFiles([]);
        }
    });

    uploadForm.addEventListener('submit', (e) => {
        e.preventDefault();

        if (isUploading) {
            return;
        }

        const selectedTopic = resolveSelectedTopic();
        if (topicSelect && selectedTopic && topicSelect.value.trim() !== selectedTopic) {
            topicSelect.value = selectedTopic;
            topicSelect.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if (!selectedTopic) {
            alert('⚠️ Please choose a section or subsection before uploading.');
            return;
        }

        if (!selectedFiles.length) {
            alert('⚠️ Please choose at least one file first.');
            fileInput.click();
            return;
        }

        const formData = new FormData();
        formData.append('topic', selectedTopic);
        const resubmitFileId = resubmitInput ? resubmitInput.value.trim() : '';
        const editFileId = editInput ? editInput.value.trim() : '';
        if (resubmitFileId) {
            if (selectedFiles.length !== 1) {
                alert('⚠️ Please attach exactly one corrected file when resubmitting.');
                return;
            }
            formData.append('resubmit_file_id', resubmitFileId);
        } else if (editFileId) {
            if (selectedFiles.length !== 1) {
                alert('⚠️ Please attach exactly one replacement file when editing.');
                return;
            }
            formData.append('edit_file_id', editFileId);
        }
        selectedFiles.forEach(file => {
            formData.append('files[]', file, file.name);
        });

        setUploadingState(true);

        fetch('upload.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                sessionStorage.removeItem('helpdeskSelectedTopic');
                sessionStorage.removeItem('helpdeskNextPage');
                if (typeof selectTopicTemplate === 'function') {
                    selectTopicTemplate('', false);
                }

                alert('✅ ' + data.message);
                resetUploadSelection({ clearMode: true });
                setTimeout(() => location.reload(), 350);
            } else {
                alert('❌ ' + data.message);
            }
        })
        .catch(err => alert('❌ Error: ' + err.message))
        .finally(() => {
            setUploadingState(false);
        });
    });
}

function deleteFile(fileId) {
    if (confirm('Delete this document?')) {
        fetch('delete_file.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({file_id: fileId})
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('✅ Document deleted!');
                location.reload();
            } else {
                alert('❌ ' + data.message);
            }
        });
    }
}

function approveFile(fileId) {
    if (confirm('Approve this document?')) {
        fetch('approve_file.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({file_id: fileId, status: 'approved'})
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('✅ Document approved!');
                location.reload();
            } else {
                alert('❌ ' + data.message);
            }
        });
    }
}

function rejectFile(fileId) {
    const reason = prompt('Enter reason for rejection:');
    if (reason) {
        fetch('approve_file.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({file_id: fileId, status: 'rejected', reason: reason})
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('❌ Document rejected!');
                location.reload();
            } else {
                alert('❌ ' + data.message);
            }
        });
    }
}

function archiveFile(fileId) {
    if (confirm('Archive this document?')) {
        fetch('approve_file.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({file_id: fileId, status: 'archived'})
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('🗄️ Document archived!');
                location.reload();
            } else {
                alert('❌ ' + data.message);
            }
        });
    }
}

function unarchiveFile(fileId) {
    if (confirm('Unarchive this document? It will be moved back to Approved.')) {
        fetch('approve_file.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({file_id: fileId, status: 'approved'})
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('✅ Document unarchived and set back to Approved!');
                location.reload();
            } else {
                alert('❌ ' + data.message);
            }
        });
    }
}

function deleteUser(userId) {
    if (confirm('Delete this user?')) {
        fetch('delete_user.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({user_id: userId})
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('✅ User deleted!');
                location.reload();
            } else {
                alert('❌ ' + data.message);
            }
        });
    }
}

// Search functionality
function initializeSearch() {
    const documentSearchInput = document.getElementById('searchFiles');
    const userSearchInput = document.getElementById('searchUsers');
    const adminGlobalSearchInput = document.getElementById('adminGlobalSearch');
    const filterSelect = document.getElementById('filterStatus');
    const adminTopicFilter = document.getElementById('filterTopic');
    const userTopicFilter = document.getElementById('filterTopicUser');
    const trackingSearchInput = document.getElementById('searchTracking');
    const trackingStatusFilter = document.getElementById('filterTrackingStatus');

    const filterTable = (input, tableId, statusFilter, topicFilter) => {
        const searchTerm = (input?.value || '').toLowerCase();
        const table = document.getElementById(tableId);
        if (!table) return;

        table.querySelectorAll('tbody tr').forEach(row => {
            const rowText = row.textContent.toLowerCase();
            const matchesText = rowText.includes(searchTerm);
            let matchesStatus = true;
            let matchesTopic = true;

            if (statusFilter) {
                const status = (statusFilter.value || '').toLowerCase();
                if (!status) {
                    matchesStatus = true;
                } else {
                    matchesStatus = rowText.includes(status);
                }
            }

            if (topicFilter && topicFilter.value) {
                matchesTopic = rowText.includes(topicFilter.value.toLowerCase());
            }

            row.style.display = matchesText && matchesStatus && matchesTopic ? '' : 'none';
        });
    };

    if (documentSearchInput) {
        documentSearchInput.addEventListener('keyup', () => filterTable(documentSearchInput, 'filesTable', filterSelect, adminTopicFilter || userTopicFilter));
    }

    if (userSearchInput) {
        userSearchInput.addEventListener('keyup', () => filterTable(userSearchInput, 'usersTable', null, null));
    }

    if (filterSelect) {
        filterSelect.addEventListener('change', () => filterTable(documentSearchInput, 'filesTable', filterSelect, adminTopicFilter || userTopicFilter));
    }

    if (adminTopicFilter) {
        adminTopicFilter.addEventListener('change', () => filterTable(documentSearchInput, 'filesTable', filterSelect, adminTopicFilter));
    }

    if (userTopicFilter) {
        userTopicFilter.addEventListener('change', () => filterTable(documentSearchInput, 'filesTable', filterSelect, userTopicFilter));
    }

    if (trackingSearchInput) {
        trackingSearchInput.addEventListener('keyup', () => filterTable(trackingSearchInput, 'trackingTable', trackingStatusFilter, null));
    }

    if (trackingStatusFilter) {
        trackingStatusFilter.addEventListener('change', () => filterTable(trackingSearchInput, 'trackingTable', trackingStatusFilter, null));
    }

    if (adminGlobalSearchInput) {
        const filterTopicRows = (searchTerm) => {
            const topicsPage = document.getElementById('topics');
            if (!topicsPage) {
                return [];
            }

            const normalizedTerm = searchTerm.trim().toLowerCase();
            const rows = Array.from(topicsPage.querySelectorAll('tbody tr'));
            const matches = [];

            rows.forEach(row => {
                const isEmpty = row.querySelector('.text-center');
                if (isEmpty) {
                    row.style.display = '';
                    return;
                }

                const rowText = row.textContent.toLowerCase();
                const visible = normalizedTerm === '' || rowText.includes(normalizedTerm);
                row.style.display = visible ? '' : 'none';
                if (visible) {
                    matches.push(row);
                }
            });

            return matches;
        };

        const filterAdminTopicCards = (searchTerm) => {
            const cards = Array.from(document.querySelectorAll('#upload .topic-template-card'));
            const normalizedTerm = searchTerm.trim().toLowerCase();
            const matches = [];

            cards.forEach(card => {
                const title = (card.dataset.topic || '').toLowerCase();
                const subitems = Array.from(card.querySelectorAll('.topic-subitem'));
                let hasMatch = normalizedTerm === '' || title.includes(normalizedTerm);

                subitems.forEach(item => {
                    const itemText = item.textContent.toLowerCase();
                    const itemMatches = normalizedTerm === '' || itemText.includes(normalizedTerm) || title.includes(normalizedTerm);
                    item.style.display = itemMatches ? '' : 'none';
                    if (itemMatches) {
                        hasMatch = true;
                    }
                });

                card.style.display = hasMatch ? '' : 'none';
                card.classList.toggle('active', normalizedTerm !== '' && hasMatch);

                const header = card.querySelector('.topic-template-header');
                if (header) {
                    header.setAttribute('aria-expanded', normalizedTerm !== '' && hasMatch ? 'true' : 'false');
                }

                if (hasMatch) {
                    matches.push(card);
                }
            });

            return matches;
        };

        const runAdminSearch = (shouldNavigate = false) => {
            const searchTerm = adminGlobalSearchInput.value || '';
            const normalizedTerm = searchTerm.trim().toLowerCase();

            if (documentSearchInput) {
                documentSearchInput.value = searchTerm;
                filterTable(documentSearchInput, 'filesTable', filterSelect, adminTopicFilter || userTopicFilter);
            }

            if (userSearchInput) {
                userSearchInput.value = searchTerm;
                filterTable(userSearchInput, 'usersTable', null, null);
            }

            if (trackingSearchInput) {
                trackingSearchInput.value = searchTerm;
                filterTable(trackingSearchInput, 'trackingTable', trackingStatusFilter, null);
            }

            const matchedTopicRows = filterTopicRows(searchTerm);
            const matchedTopicCards = filterAdminTopicCards(searchTerm);

            if (!shouldNavigate) {
                return;
            }

            if (!normalizedTerm) {
                showPage('dashboard');
                return;
            }

            const visibleDocRow = document.querySelector('#allfiles tbody tr:not([style*="display: none"])');
            if (visibleDocRow && !visibleDocRow.querySelector('.text-center')) {
                showPage('allfiles');
                visibleDocRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }

            const visiblePendingRow = document.querySelector('#pending tbody tr:not([style*="display: none"])');
            if (visiblePendingRow && !visiblePendingRow.querySelector('.text-center')) {
                showPage('pending');
                visiblePendingRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }

            const visibleTrackingRow = document.querySelector('#tracking tbody tr:not([style*="display: none"])');
            if (visibleTrackingRow && !visibleTrackingRow.querySelector('.text-center')) {
                showPage('tracking');
                visibleTrackingRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }

            const visibleUserRow = document.querySelector('#users tbody tr:not([style*="display: none"])');
            if (visibleUserRow && !visibleUserRow.querySelector('.text-muted')) {
                showPage('users');
                visibleUserRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }

            if (matchedTopicRows.length) {
                showPage('topics');
                matchedTopicRows[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }

            const firstTopicSubitem = matchedTopicCards.length
                ? matchedTopicCards[0].querySelector('.topic-subitem:not([style*="display: none"])')
                : null;

            if (firstTopicSubitem?.dataset.value) {
                showPage('upload');
                selectTopicTemplate(firstTopicSubitem.dataset.value);
                return;
            }

            if (matchedTopicCards.length) {
                showPage('upload');
                matchedTopicCards[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }

            const candidatePages = ['pending', 'allfiles', 'tracking', 'archive', 'users'];
            const matchPage = candidatePages.find(pageId => {
                const section = document.getElementById(pageId);
                return section && section.textContent.toLowerCase().includes(normalizedTerm);
            });

            if (matchPage) {
                showPage(matchPage);
                const section = document.getElementById(matchPage);
                if (section) {
                    section.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            } else {
                alert('No matching result found.');
            }
        };

        window.runAdminSearch = runAdminSearch;
        adminGlobalSearchInput.addEventListener('input', () => runAdminSearch(false));
        adminGlobalSearchInput.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                runAdminSearch(true);
            }
        });
    }
}

function submitTopicAction(topic, successMessage) {
    return fetch('topics.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ topic })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const topicInput = document.getElementById('newTopicInput');
            const sectionInput = document.getElementById('newSectionInput');
            const underSectionInput = document.getElementById('underSectionInput');
            if (topicInput) topicInput.value = '';
            if (sectionInput) sectionInput.value = '';
            if (underSectionInput) underSectionInput.value = '';
            updateTopicManagerState(data.topics || []);
            sessionStorage.setItem(TOPIC_SCROLL_TARGET_KEY, topic);
            scrollTopicManagerToTarget(topic);
            alert(successMessage);
            sessionStorage.setItem(PAGE_STATE_KEY, 'topics');
            setTimeout(() => location.reload(), 350);
        } else {
            alert('❌ ' + data.message);
        }
        return data;
    });
}

function addTopic() {
    const topicInput = document.getElementById('newTopicInput');
    if (!topicInput) return;

    const topic = topicInput.value.trim();
    if (!topic) {
        alert('Please enter a main section name.');
        return;
    }

    submitTopicAction(topic, '✅ Main section added');
}

function addSection() {
    const parentTopicSelect = document.getElementById('parentTopicSelect');
    const sectionInput = document.getElementById('newSectionInput');
    if (!parentTopicSelect || !sectionInput) return;

    const parentTopic = parentTopicSelect.value.trim();
    const section = sectionInput.value.trim();

    if (!parentTopic) {
        alert('Please choose a main section first.');
        return;
    }

    if (!section) {
        alert('Please enter a section name.');
        return;
    }

    submitTopicAction(`${parentTopic} / ${section}`, '✅ Section added');
}

function addUnderSection() {
    const sectionParentSelect = document.getElementById('sectionParentSelect');
    const underSectionInput = document.getElementById('underSectionInput');
    if (!sectionParentSelect || !underSectionInput) return;

    const sectionParent = sectionParentSelect.value.trim();
    const underSection = underSectionInput.value.trim();

    if (!sectionParent) {
        alert('Please choose a section first.');
        return;
    }

    if (!underSection) {
        alert('Please enter an under section name.');
        return;
    }

    submitTopicAction(`${sectionParent} / ${underSection}`, '✅ Under section added');
}

function addSubsection() {
    addSection();
}

let activeTopicTreeDrag = null;
let topicTreeOrderSaveToken = 0;

function removeTopic(topic) {
    if (!confirm(`Delete the topic "${topic}"?`)) {
        return;
    }
    fetch('topics.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action: 'delete', topic })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            updateTopicManagerState(data.topics || []);
            alert('✅ Section deleted');
            sessionStorage.setItem(PAGE_STATE_KEY, 'topics');
            setTimeout(() => location.reload(), 350);
        } else {
            alert('❌ ' + data.message);
        }
    });
}

function initializeTopicAdminManager() {
    const topicManager = document.querySelector('.topic-manager[data-topics]');
    if (!topicManager) return;

    let topics = [];
    try {
        topics = JSON.parse(topicManager.dataset.topics || '[]');
    } catch (error) {
        topics = [];
    }

    updateTopicManagerState(topics);

    const pendingScrollTarget = sessionStorage.getItem(TOPIC_SCROLL_TARGET_KEY);
    if (pendingScrollTarget) {
        scrollTopicManagerToTarget(pendingScrollTarget);
        sessionStorage.removeItem(TOPIC_SCROLL_TARGET_KEY);
    }

    const syncPreview = () => syncAdminTopicSelectionPreview();
    const parentTopicSelect = document.getElementById('parentTopicSelect');
    const sectionParentSelect = document.getElementById('sectionParentSelect');
    const newSectionInput = document.getElementById('newSectionInput');
    const underSectionInput = document.getElementById('underSectionInput');

    if (parentTopicSelect) {
        parentTopicSelect.addEventListener('change', syncPreview);
    }
    if (sectionParentSelect) {
        sectionParentSelect.addEventListener('change', syncPreview);
    }
    if (newSectionInput) {
        newSectionInput.addEventListener('input', syncPreview);
    }
    if (underSectionInput) {
        underSectionInput.addEventListener('input', syncPreview);
    }

    syncAdminTopicSelectionPreview();
}

function syncAdminTopicSelectionPreview() {
    const parentTopicSelect = document.getElementById('parentTopicSelect');
    const sectionParentSelect = document.getElementById('sectionParentSelect');
    const newSectionInput = document.getElementById('newSectionInput');
    const underSectionInput = document.getElementById('underSectionInput');

    if (!parentTopicSelect && !sectionParentSelect) {
        return;
    }

    const selectedMain = parentTopicSelect ? parentTopicSelect.value.trim() : '';
    const typedSection = newSectionInput ? newSectionInput.value.trim() : '';
    const selectedSectionPath = sectionParentSelect ? sectionParentSelect.value.trim() : '';
    const typedUnderSection = underSectionInput ? underSectionInput.value.trim() : '';

    let previewTopic = '';
    if (selectedSectionPath) {
        previewTopic = selectedSectionPath;
        if (typedUnderSection) {
            previewTopic = `${previewTopic} / ${typedUnderSection}`;
        }
    } else if (selectedMain) {
        previewTopic = typedSection ? `${selectedMain} / ${typedSection}` : selectedMain;
    }

    updateDashboardUploadBar(previewTopic);
}

function updateTopicManagerState(topics) {
    const topicManager = document.querySelector('.topic-manager[data-topics]');
    if (topicManager) {
        topicManager.dataset.topics = JSON.stringify(topics || []);
    }

    renderTopicList(topics || []);
    initializeTopicTreeToggles();
    initializeTopicTreeDragAndDrop();
    renderTopicParentOptions(topics || []);
    renderSectionParentOptions(topics || []);
}

function buildTopicTree(topics) {
    const rootNodes = [];

    (topics || []).forEach(rawTopic => {
        const parts = String(rawTopic)
            .split(' / ')
            .map(part => part.trim())
            .filter(Boolean);

        if (!parts.length) {
            return;
        }

        let currentLevel = rootNodes;
        const pathParts = [];

        parts.forEach((part, index) => {
            pathParts.push(part);
            const fullPath = pathParts.join(' / ');
            let existingNode = currentLevel.find(node => node.path === fullPath);

            if (!existingNode) {
                existingNode = {
                    name: part,
                    path: fullPath,
                    depth: index + 1,
                    children: []
                };
                currentLevel.push(existingNode);
            }

            currentLevel = existingNode.children;
        });
    });

    return rootNodes;
}

function renderTopicList(topics) {
    const topicList = document.getElementById('topicList');
    if (!topicList) return;

    const topicTree = buildTopicTree(topics);

    const renderNodes = (nodes) => nodes.map(node => `
        <div class="topic-tree-node depth-${node.depth}${node.children.length ? ' has-children' : ''}" data-topic-path="${node.path.replace(/"/g, '&quot;')}">
            <div class="topic-tree-row">
                <div class="topic-tree-drag-handle" draggable="true" aria-hidden="true" title="Drag to reorder">⋮⋮</div>
                ${node.children.length
                    ? `<button type="button" class="topic-tree-toggle" aria-expanded="false"><span class="topic-tree-copy"><strong>${node.name}</strong></span><span class="topic-tree-arrow" aria-hidden="true"></span></button>`
                    : `<div class="topic-tree-copy"><strong>${node.name}</strong></div>`}
                <button type="button" class="topic-tree-remove" onclick='removeTopic(${JSON.stringify(node.path)})'>Remove</button>
            </div>
            ${node.children.length ? `<div class="topic-tree-children">${renderNodes(node.children)}</div>` : ''}
        </div>
    `).join('');

    topicList.innerHTML = topicTree.length
        ? renderNodes(topicTree)
        : '<div class="empty-help-state compact-empty-state"><h3>No sections defined yet</h3><p>Add a main topic, section, or under section to build the admin structure.</p></div>';
}

function initializeTopicTreeToggles() {
    const topicList = document.getElementById('topicList');
    if (!topicList) {
        return;
    }

    topicList.querySelectorAll('.topic-tree-toggle').forEach(toggle => {
        toggle.addEventListener('click', () => {
            const node = toggle.closest('.topic-tree-node');
            if (!(node instanceof Element)) {
                return;
            }

            const isOpen = node.classList.contains('is-open');
            node.classList.toggle('is-open', !isOpen);
            toggle.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
        });
    });
}

function initializeTopicTreeDragAndDrop() {
    const topicList = document.getElementById('topicList');
    if (!topicList) {
        return;
    }

    topicList.querySelectorAll('.topic-tree-drag-handle').forEach(handle => {
        handle.addEventListener('dragstart', handleTopicTreeDragStart);
        handle.addEventListener('dragend', handleTopicTreeDragEnd);
    });

    [topicList, ...topicList.querySelectorAll('.topic-tree-children')].forEach(container => {
        container.addEventListener('dragover', handleTopicTreeDragOver);
        container.addEventListener('drop', handleTopicTreeDrop);
    });
}

function handleTopicTreeDragStart(event) {
    const handle = event.currentTarget;
    const node = handle instanceof Element ? handle.closest('.topic-tree-node') : null;
    if (!(node instanceof Element)) {
        return;
    }

    activeTopicTreeDrag = {
        node,
        originContainer: node.parentElement,
        moved: false
    };

    node.classList.add('is-dragging');

    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', node.dataset.topicPath || '');
    }
}

function handleTopicTreeDragOver(event) {
    if (!activeTopicTreeDrag?.node) {
        return;
    }

    const container = event.currentTarget;
    if (!(container instanceof Element) || activeTopicTreeDrag.node.parentElement !== container) {
        return;
    }

    event.preventDefault();

    const nextNode = getTopicTreeDragAfterElement(container, event.clientY, activeTopicTreeDrag.node);
    if (!nextNode) {
        if (container.lastElementChild !== activeTopicTreeDrag.node) {
            container.appendChild(activeTopicTreeDrag.node);
            activeTopicTreeDrag.moved = true;
        }
        return;
    }

    if (nextNode !== activeTopicTreeDrag.node) {
        container.insertBefore(activeTopicTreeDrag.node, nextNode);
        activeTopicTreeDrag.moved = true;
    }
}

function handleTopicTreeDrop(event) {
    if (!activeTopicTreeDrag?.node) {
        return;
    }

    const container = event.currentTarget;
    if (!(container instanceof Element) || activeTopicTreeDrag.node.parentElement !== container) {
        return;
    }

    event.preventDefault();
}

function handleTopicTreeDragEnd() {
    if (!activeTopicTreeDrag?.node) {
        return;
    }

    const { node, moved } = activeTopicTreeDrag;
    node.classList.remove('is-dragging');
    activeTopicTreeDrag = null;

    if (!moved) {
        return;
    }

    saveTopicTreeOrder();
}

function getTopicTreeDragAfterElement(container, pointerY, draggedNode) {
    const siblingNodes = Array.from(container.children).filter(node => {
        return node instanceof Element && node.classList.contains('topic-tree-node') && node !== draggedNode;
    });

    let closestOffset = Number.NEGATIVE_INFINITY;
    let closestNode = null;

    siblingNodes.forEach(node => {
        const rect = node.getBoundingClientRect();
        const offset = pointerY - rect.top - (rect.height / 2);
        if (offset < 0 && offset > closestOffset) {
            closestOffset = offset;
            closestNode = node;
        }
    });

    return closestNode;
}

function collectTopicTreeOrder(container) {
    const orderedTopics = [];

    Array.from(container.children).forEach(child => {
        if (!(child instanceof Element) || !child.classList.contains('topic-tree-node')) {
            return;
        }

        const topicPath = child.dataset.topicPath || '';
        if (topicPath) {
            orderedTopics.push(topicPath);
        }

        const childContainer = Array.from(child.children).find(grandchild => {
            return grandchild instanceof Element && grandchild.classList.contains('topic-tree-children');
        });

        if (childContainer instanceof Element) {
            orderedTopics.push(...collectTopicTreeOrder(childContainer));
        }
    });

    return orderedTopics;
}

function saveTopicTreeOrder() {
    const topicList = document.getElementById('topicList');
    if (!topicList) {
        return;
    }

    const orderedTopics = collectTopicTreeOrder(topicList);
    if (!orderedTopics.length) {
        return;
    }

    const requestToken = ++topicTreeOrderSaveToken;

    fetch('topics.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action: 'reorder', topics: orderedTopics })
    })
    .then(r => r.json())
    .then(data => {
        if (requestToken !== topicTreeOrderSaveToken) {
            return;
        }

        if (data.success) {
            updateTopicManagerState(data.topics || orderedTopics);
            return;
        }

        alert('❌ ' + (data.message || 'Unable to save topic order'));
        location.reload();
    })
    .catch(() => {
        if (requestToken !== topicTreeOrderSaveToken) {
            return;
        }

        alert('❌ Unable to save topic order');
        location.reload();
    });
}

function scrollTopicManagerToTarget(topicPath) {
    const topicList = document.getElementById('topicList');
    if (!topicList) return;

    const escapedPath = typeof CSS !== 'undefined' && typeof CSS.escape === 'function'
        ? CSS.escape(topicPath)
        : topicPath.replace(/"/g, '\\"');
    const targetNode = topicList.querySelector(`[data-topic-path="${escapedPath}"]`);

    if (targetNode) {
        let ancestorNode = targetNode.parentElement ? targetNode.parentElement.closest('.topic-tree-node') : null;
        while (ancestorNode) {
            ancestorNode.classList.add('is-open');
            const row = ancestorNode.children[0];
            const toggleButton = row ? row.querySelector('.topic-tree-toggle') : null;
            if (toggleButton) {
                toggleButton.setAttribute('aria-expanded', 'true');
            }
            ancestorNode = ancestorNode.parentElement ? ancestorNode.parentElement.closest('.topic-tree-node') : null;
        }

        targetNode.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }

    const lastNode = topicList.querySelector('.topic-tree-node:last-of-type');
    if (lastNode) {
        lastNode.scrollIntoView({ behavior: 'smooth', block: 'end' });
    }
}

function renderTopicParentOptions(topics) {
    const parentTopicSelect = document.getElementById('parentTopicSelect');
    if (!parentTopicSelect) return;

    const currentValue = parentTopicSelect.value;
    const uniqueParents = [];

    topics.forEach(topic => {
        const parts = String(topic).split(' / ').map(part => part.trim()).filter(Boolean);
        const parent = parts.length ? parts[0] : '';
        if (parts.length === 1 && parent && !uniqueParents.includes(parent)) {
            uniqueParents.push(parent);
        }
    });

    parentTopicSelect.innerHTML = '';
    const defaultOption = document.createElement('option');
    defaultOption.value = '';
    defaultOption.textContent = 'Choose main section';
    parentTopicSelect.appendChild(defaultOption);

    uniqueParents.forEach(parent => {
        const option = document.createElement('option');
        option.value = parent;
        option.textContent = parent;
        parentTopicSelect.appendChild(option);
    });

    if (currentValue && uniqueParents.includes(currentValue)) {
        parentTopicSelect.value = currentValue;
    }
}

function renderSectionParentOptions(topics) {
    const sectionParentSelect = document.getElementById('sectionParentSelect');
    if (!sectionParentSelect) return;

    const currentValue = sectionParentSelect.value;
    const sectionPaths = [];

    (topics || []).forEach(topic => {
        const parts = String(topic).split(' / ').map(part => part.trim()).filter(Boolean);
        if (parts.length === 2) {
            const sectionPath = parts.join(' / ');
            if (!sectionPaths.includes(sectionPath)) {
                sectionPaths.push(sectionPath);
            }
        }
    });

    sectionParentSelect.innerHTML = '';
    const defaultOption = document.createElement('option');
    defaultOption.value = '';
    defaultOption.textContent = 'Choose section';
    sectionParentSelect.appendChild(defaultOption);

    sectionPaths.forEach(sectionPath => {
        const option = document.createElement('option');
        option.value = sectionPath;
        option.textContent = sectionPath;
        sectionParentSelect.appendChild(option);
    });

    if (currentValue && sectionPaths.includes(currentValue)) {
        sectionParentSelect.value = currentValue;
    }
}

function initializeNotificationCenter() {
    const centers = document.querySelectorAll('.notification-center');
    if (!centers.length) return;

    centers.forEach(center => {
        const bell = center.querySelector('.notification-bell[data-notice-target]');
        if (!bell) return;

        const menu = document.getElementById(bell.dataset.noticeTarget);
        if (!menu) return;

        bell.addEventListener('click', (e) => {
            e.stopPropagation();
            const shouldOpen = !menu.classList.contains('open');
            closeAllNotificationMenus();

            if (shouldOpen) {
                menu.classList.add('open');
                bell.setAttribute('aria-expanded', 'true');

                const unreadCount = parseInt(document.getElementById('notif-count')?.textContent || '0', 10) || 0;
                if (unreadCount > 0) {
                    markNotificationsRead(false);
                }
            }
        });

        menu.addEventListener('click', (e) => e.stopPropagation());
    });

    document.addEventListener('click', closeAllNotificationMenus);
}

function closeAllNotificationMenus() {
    document.querySelectorAll('.notification-menu.open').forEach(menu => menu.classList.remove('open'));
    document.querySelectorAll('.notification-bell[data-notice-target]').forEach(bell => bell.setAttribute('aria-expanded', 'false'));
}

function updateNotificationBadge(count) {
    document.querySelectorAll('#notif-count').forEach(badge => {
        badge.textContent = count;
        badge.style.display = count > 0 ? 'flex' : 'none';
    });
}

function openNotificationsPanel(sectionId) {
    closeAllNotificationMenus();

    const dashboardNav = document.querySelector('.nav-item[data-page="dashboard"]');
    if (dashboardNav) {
        dashboardNav.click();
    }

    const panel = document.getElementById(sectionId);
    if (panel) {
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function closeNotificationBanner(notificationId) {
    const banner = document.getElementById(`notif-${notificationId}`);
    if (banner) {
        banner.style.opacity = '0';
        banner.style.transform = 'translateY(-8px)';
        setTimeout(() => banner.remove(), 180);
    }

    markNotificationsRead(false, notificationId);
}

function markNotificationsRead(showAlert = true, notificationId = null) {
    return fetch('mark_notifications_read.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(notificationId ? { notification_id: notificationId } : {})
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            if (notificationId) {
                document.querySelectorAll(`[data-notification-id="${notificationId}"]`).forEach(item => item.classList.remove('unread'));
            } else {
                document.querySelectorAll('.notification-item.unread, .notice-item.unread').forEach(item => item.classList.remove('unread'));
            }

            const remainingUnread = document.querySelectorAll('.notification-item.unread, .notice-item.unread').length;
            updateNotificationBadge(remainingUnread);

            if (showAlert) {
                alert('✅ All notifications marked as read!');
            }
        } else if (showAlert) {
            alert('❌ ' + data.message);
        }

        return data;
    })
    .catch(err => {
        if (showAlert) {
            alert('❌ Error: ' + err.message);
        } else {
            console.error(err);
        }

        return { success: false, message: err.message };
    });
}
function openViewAllModal() {
    const modal = document.getElementById('viewAllModal');
    if (!modal) return;
    modal.classList.remove('va-modal-hidden');
    document.body.style.overflow = 'hidden';
    const inp = document.getElementById('vaSearchInput');
    if (inp) { inp.value = ''; filterViewAll(''); inp.focus(); }
}

function closeViewAllModal() {
    const modal = document.getElementById('viewAllModal');
    if (modal) modal.classList.add('va-modal-hidden');
    document.body.style.overflow = '';
}

function filterViewAll(query) {
    const q = query.toLowerCase().trim();
    const rows = document.querySelectorAll('#vaTableBody .va-row');
    let visible = 0;
    rows.forEach(function(row) {
        const text = (row.dataset.search || '').toLowerCase();
        const show = !q || text.includes(q);
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    const count = document.getElementById('vaCount');
    if (count) count.textContent = visible + ' file(s)';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { closeViewAllModal(); }
});
