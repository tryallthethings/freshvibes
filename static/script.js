document.addEventListener('DOMContentLoaded', () => {
	const freshvibesView = document.querySelector('.freshvibes-view');
	if (freshvibesView) {
		initializeDashboard(freshvibesView);
	}
});

function initializeDashboard(freshvibesView) {
	// --- STATE ---
	let state = { layout: [], feeds: {}, activeTabId: null, allPlacedFeedIds: new Set() };

	// --- DOM & CONFIG ---
	const {
		xextensionFreshvibesviewGetLayoutUrl: getLayoutUrl = '',
		xextensionFreshvibesviewSaveLayoutUrl: saveLayoutUrl = '',
		xextensionFreshvibesviewTabActionUrl: tabActionUrl = '',
		xextensionFreshvibesviewSetActiveTabUrl: setActiveTabUrl = '',
		xextensionFreshvibesviewCsrfToken: csrfToken = '',
		xextensionFreshvibesviewSaveFeedSettingsUrl: saveFeedSettingsUrl = '',
		xextensionFreshvibesviewMoveFeedUrl: moveFeedUrl = '',
		xextensionFreshvibesviewRefreshInterval: refreshInterval = '',
		xextensionFreshvibesviewMarkReadUrl: markReadUrl = '',
		xextensionFreshvibesviewFeedUrl: feedUrl = '',
		xextensionFreshvibesviewSearchAuthorUrl: searchAuthorUrl = '',
		xextensionFreshvibesviewSearchTagUrl: searchTagUrl = '',
		xextensionFreshvibesviewDateFormat: dateFormat = '',
		xextensionFreshvibesviewMarkFeedReadUrl: markFeedReadUrl = '',
		xextensionFreshvibesviewMarkTabReadUrl: markTabReadUrl = '',
		xextensionFreshvibesviewMode: viewMode = '',
		xextensionFreshvibesviewConfirmMarkRead: confirmMarkRead = '',

	} = freshvibesView.dataset;
	const isCategoryMode = viewMode === 'categories';

	// Handle potentially undefined values gracefully
	const dashboardUrl = freshvibesView.dataset.xextensionFreshvibesviewDashboardUrl;
	const refreshEnabled = freshvibesView.dataset.xextensionFreshvibesviewRefreshEnabled === 'true' || freshvibesView.dataset.xextensionFreshvibesviewRefreshEnabled === '1';

	const trEl = document.getElementById('freshvibes-i18n');
	const tr = trEl ? JSON.parse(trEl.textContent) : {};
	if (trEl) trEl.remove();

	const tabsContainer = freshvibesView.querySelector('.freshvibes-tabs');
	const panelsContainer = freshvibesView.querySelector('.freshvibes-panels');
	const templates = {
		tabLink: document.getElementById('template-tab-link'),
		tabPanel: document.getElementById('template-tab-panel'),
		feedContainer: document.getElementById('template-feed-container'),
	};

	const entryModal = document.getElementById('freshvibes-entry-modal');
	const modalTitle = entryModal?.querySelector('.fv-modal-title');
	const modalDate = entryModal?.querySelector('.fv-modal-date');
	const modalExcerpt = entryModal?.querySelector('.fv-modal-excerpt');
	const modalLink = entryModal?.querySelector('.fv-modal-link');
	const modalFeed = entryModal?.querySelector('.fv-modal-feed');
	const modalFeedIcon = entryModal?.querySelector('.fv-modal-feed-icon');
	const modalFeedName = entryModal?.querySelector('.fv-modal-feed-name');
	const modalAuthor = entryModal?.querySelector('.fv-modal-author');
	const modalTags = entryModal?.querySelector('.fv-modal-tags');
	const modalTagsContainer = entryModal?.querySelector('.fv-modal-tags-container');
	const modalMarkUnread = entryModal?.querySelector('.fv-modal-mark-unread');
	const modalAuthorWrapper = entryModal?.querySelector('.fv-modal-author-wrapper');
	const modalAuthorPrefix = entryModal?.querySelector('.fv-modal-author-prefix');
	const dateMode = freshvibesView.dataset.xextensionFreshvibesviewDateMode || 'absolute';
	const bulkApplyFeedsUrl = freshvibesView.dataset.xextensionFreshvibesviewBulkApplyFeedsUrl || '';
	const bulkApplyTabsUrl = freshvibesView.dataset.xextensionFreshvibesviewBulkApplyTabsUrl || '';
	const resetFeedsUrl = freshvibesView.dataset.xextensionFreshvibesviewResetFeedsUrl || '';
	const resetTabsUrl = freshvibesView.dataset.xextensionFreshvibesviewResetTabsUrl || '';

	// --- RENDER FUNCTIONS ---
	function render() {
		renderTabs();
		renderPanels();
		activateTab(state.activeTabId || state.layout[0]?.id, false);
	}

	function renderTabs() {
		// Store reference to subscription buttons before clearing
		const subscriptionButtons = document.querySelector('.moved-subscription-buttons');
		const parentElement = subscriptionButtons?.parentElement;

		tabsContainer.innerHTML = '';
		state.layout.forEach(tab => {
			const link = createTabLink(tab);

			// Calculate and show unread count
			let tabUnreadCount = 0;
			if (tab.columns) {
				Object.values(tab.columns).forEach(feedIds => {
					feedIds.forEach(feedId => {
						const feed = state.feeds[feedId];
						if (feed && feed.nbUnread) {
							tabUnreadCount += feed.nbUnread;
						}
					});
				});
			}

			const unreadBadge = link.querySelector('.tab-unread-count');
			if (unreadBadge && tabUnreadCount > 0) {
				unreadBadge.textContent = tabUnreadCount;
				unreadBadge.classList.add('has-count'); // Use class to show
			}

			tabsContainer.appendChild(link);
		});
		if (!isCategoryMode) {
			const addButton = document.createElement('button');
			addButton.type = 'button';
			addButton.className = 'tab-add-button';
			addButton.textContent = '+';
			addButton.title = tr.add_tab || 'Add new tab';
			addButton.ariaLabel = tr.add_tab || 'Add new tab';
			tabsContainer.appendChild(addButton);
		}

		// Add bulk settings button
		const bulkButton = document.createElement('button');
		bulkButton.type = 'button';
		bulkButton.className = 'tab-bulk-button';
		bulkButton.id = 'bulk-settings-btn';
		bulkButton.innerHTML = '//';
		bulkButton.title = tr.bulk_settings || 'Bulk Settings';
		bulkButton.ariaLabel = tr.bulk_settings || 'Bulk Settings';
		tabsContainer.appendChild(bulkButton);

		// Re-append subscription buttons if they exist
		if (subscriptionButtons) {
			tabsContainer.appendChild(subscriptionButtons);
		}
	}

	function renderPanels() {
		panelsContainer.innerHTML = '';
		state.layout.forEach(tab => panelsContainer.appendChild(createTabPanel(tab)));
	}

	function createTabLink(tab) {
		const link = templates.tabLink.content.cloneNode(true).firstElementChild;
		link.dataset.tabId = tab.id;

		// Apply saved colors via CSS variables
		if (tab.bg_color) {
			link.style.setProperty('--tab-bg-color', tab.bg_color);
			link.style.setProperty('--tab-font-color', tab.font_color || getContrastColor(tab.bg_color));
			link.classList.add('has-custom-color');
		}

		const iconSpan = link.querySelector('.tab-icon');
		if (iconSpan) {
			iconSpan.textContent = tab.icon || '';
			if (tab.icon_color) {
				iconSpan.style.setProperty('--tab-icon-color', tab.icon_color);
			}
		}
		link.querySelector('.tab-name').textContent = tab.name;
		const iconInput = link.querySelector('.tab-icon-input');
		if (iconInput) iconInput.value = tab.icon || '';
		const colorInput = link.querySelector('.tab-icon-color-input');
		if (colorInput) colorInput.value = tab.icon_color || '#000000';

		const settingsButton = link.querySelector('.tab-settings-button');
		if (settingsButton) {
			settingsButton.innerHTML = '&#x25BC;';
		}

		const delBtn = link.querySelector('.tab-action-delete');
		if (delBtn) {
			if (isCategoryMode) {
				delBtn.remove();
			} else if (state.layout.length <= 1) {
				delBtn.classList.add('hidden');
			}
		}

		// Set active column button
		const columnButtons = link.querySelectorAll('.columns-selector button');
		columnButtons.forEach(btn => {
			btn.classList.toggle('active', parseInt(btn.dataset.columns) === tab.num_columns);
		});

		// Set background color input value
		const bgColorInput = link.querySelector('.tab-bg-color-input');
		if (bgColorInput) {
			if (tab.bg_color) {
				bgColorInput.value = tab.bg_color;
			} else {
				// Set default color from computed styles
				const tempTab = document.createElement('div');
				tempTab.className = 'freshvibes-tab';
				document.body.appendChild(tempTab);
				const defaultBg = window.getComputedStyle(tempTab).backgroundColor;
				document.body.removeChild(tempTab);

				const rgb = defaultBg.match(/\d+/g);
				if (rgb) {
					const hex = '#' + rgb.map(x => parseInt(x).toString(16).padStart(2, '0')).join('');
					bgColorInput.value = hex;
				}
			}
		}

		// Show unread count
		const unreadCount = link.querySelector('.tab-unread-count');
		if (unreadCount && tab.unread_count > 0) {
			unreadCount.textContent = tab.unread_count;
			unreadCount.classList.add('has-count');
			unreadCount.title = tr.confirm_mark_tab_read || 'Mark all entries in this tab as read?';
		}

		return link;
	}

	function createTabPanel(tab) {
		const panel = templates.tabPanel.content.cloneNode(true).firstElementChild;
		panel.id = tab.id;
		return panel;
	}

	function setupAutoRefresh() {
		if (!refreshEnabled) {
			return;
		}

		const intervalMinutes = parseInt(refreshInterval, 10) || 15;
		const refreshMs = intervalMinutes * 60 * 1000;

		if (refreshMs <= 0) {
			return;
		}

		setInterval(() => {
			const isInteracting = document.querySelector('.tab-settings-menu.active, .feed-settings-editor.active') ||
				(document.activeElement && ['INPUT', 'TEXTAREA', 'BUTTON', 'A'].includes(document.activeElement.tagName));

			if (!isInteracting && dashboardUrl) {
				window.location.href = dashboardUrl;
			}
		}, refreshMs);
	}

	function renderTabContent(tab) {
		const panel = document.getElementById(tab.id);
		if (!panel) return;

		const columnsContainer = panel.querySelector('.freshvibes-columns');

		// Destroy any existing Sortable instances before clearing the DOM
		if (columnsContainer) {
			columnsContainer.querySelectorAll('.freshvibes-column').forEach(column => {
				if (column.sortable) {
					column.sortable.destroy();
					delete column.sortable;
				}
			});
		}

		columnsContainer.innerHTML = '';
		columnsContainer.className = `freshvibes-columns columns-${tab.num_columns}`;

		const columns = Array.from({ length: tab.num_columns }, (_, i) => {
			const colDiv = document.createElement('div');
			colDiv.className = 'freshvibes-column';
			colDiv.dataset.columnId = `col${i + 1}`;
			columnsContainer.appendChild(colDiv);
			return colDiv;
		});

		// This set prevents a feed from being drawn more than once in a single render.
		const renderedFeeds = new Set();

		// Part 1: Render feeds that are explicitly placed in the current tab's layout.
		if (tab.columns && typeof tab.columns === 'object') {
			Object.entries(tab.columns).forEach(([colId, feedIds]) => {
				const colIndex = parseInt(colId.replace('col', ''), 10) - 1;
				if (columns[colIndex] && Array.isArray(feedIds)) {
					feedIds.forEach(feedId => {
						const feedIdStr = String(feedId);
						let feedData = state.feeds[feedIdStr] || state.feeds[feedId];

						if (feedData && !renderedFeeds.has(feedIdStr)) {
							columns[colIndex].appendChild(createFeedContainer(feedData, tab.id));
							renderedFeeds.add(feedIdStr);
						}
					});
				}
			});
		}

		// Part 2: On the very first tab, also render any feeds that are not placed in *any* tab's layout.
		const isFirstTab = state.layout.length > 0 && state.layout[0].id === tab.id;
		if (isFirstTab) {
			Object.entries(state.feeds).forEach(([feedKey, feedData]) => {
				const feedIdStr = String(feedData.id);
				if (!state.allPlacedFeedIds.has(feedIdStr) && !renderedFeeds.has(feedIdStr)) {
					if (columns[0]) {
						columns[0].appendChild(createFeedContainer(feedData, tab.id));
						renderedFeeds.add(feedIdStr);
					}
				}
			});
		}

		// Initialize sortable after a delay to ensure DOM is ready
		setTimeout(() => {
			initializeSortable(columns);
		}, 100);
	}

	function createFeedContainer(feed, sourceTabId) {
		const container = templates.feedContainer.content.cloneNode(true).firstElementChild;

		// Ensure we have valid feed data
		if (!feed || !feed.id) {
			return document.createElement('div'); // Return empty div to prevent errors
		}

		container.dataset.feedId = String(feed.id);
		container.dataset.sourceTabId = sourceTabId;
		container.classList.toggle('fontsize-xsmall', feed.currentFontSize === 'xsmall');
		container.classList.toggle('fontsize-small', feed.currentFontSize === 'small');
		container.classList.toggle('fontsize-large', feed.currentFontSize === 'large');
		container.classList.toggle('fontsize-xlarge', feed.currentFontSize === 'xlarge');

		container.classList.toggle('display-compact', feed.currentDisplayMode === 'compact');
		container.classList.toggle('display-detailed', feed.currentDisplayMode === 'detailed');

		const favicon = container.querySelector('.feed-favicon');
		if (favicon) {
			if (feed.favicon) {
				favicon.src = feed.favicon;
				favicon.classList.remove('hidden');
			} else {
				favicon.classList.add('hidden');
			}
		}

		const titleElement = container.querySelector('.feed-title');
		if (titleElement && feed.website) {
			const titleLink = document.createElement('a');
			titleLink.href = feed.website;
			titleLink.target = '_blank';
			titleLink.rel = 'noopener noreferrer';
			titleLink.className = 'feed-title-link';
			// Create text node to avoid HTML injection
			titleLink.appendChild(document.createTextNode(feed.name || 'Unnamed Feed'));
			titleElement.appendChild(titleLink);
		} else if (titleElement) {
			titleElement.appendChild(document.createTextNode(feed.name || 'Unnamed Feed'));
		}

		const headerElement = container.querySelector('.freshvibes-container-header');
		if (headerElement) {
			// Apply header color via CSS variable
			if (feed.currentHeaderColor) {
				headerElement.style.setProperty('--header-bg-color', feed.currentHeaderColor);
				headerElement.style.setProperty('--header-font-color', getContrastColor(feed.currentHeaderColor));
				headerElement.classList.add('has-custom-color');
			}

			if (feed.nbUnread > 0) {
				const unreadBadge = document.createElement('span');
				unreadBadge.className = 'feed-unread-badge';
				unreadBadge.textContent = feed.nbUnread;
				unreadBadge.title = tr.mark_all_read || 'Mark all as read';
				headerElement.insertBefore(unreadBadge, headerElement.querySelector('.feed-settings'));
			}
		}

		const contentDiv = container.querySelector('.freshvibes-container-content');
		if (contentDiv) {
			contentDiv.innerHTML = '';
			// Apply max-height
			if (feed.currentMaxHeight && !['unlimited', 'fit'].includes(feed.currentMaxHeight)) {
				contentDiv.style.maxHeight = feed.currentMaxHeight + 'px';
			} else {
				contentDiv.style.maxHeight = '';
			}
			if (feed.entries && Array.isArray(feed.entries) && feed.entries.length > 0 && !feed.entries.error) {
				const ul = document.createElement('ul');
				feed.entries.forEach(entry => {
					ul.appendChild(createEntryItem(entry, feed));
				});
				contentDiv.appendChild(ul);
			} else {
				const p = document.createElement('p');
				p.className = 'no-entries';
				p.textContent = feed.entries?.error || tr.no_entries || 'No recent articles.';
				contentDiv.appendChild(p);
			}
		}

		const editor = container.querySelector('.feed-settings-editor');
		if (editor) {
			const limitSelect = editor.querySelector('.feed-limit-select');
			if (limitSelect) {
				[5, 10, 15, 20, 25, 30, 40, 50, 'unlimited'].forEach(val => {
					const label = val === 'unlimited' ? (tr.unlimited || 'Unlimited') : val;
					const opt = new Option(label, val, String(val) === String(feed.currentLimit), String(val) === String(feed.currentLimit));
					limitSelect.add(opt);
				});
			}

			const fontSelect = editor.querySelector('.feed-fontsize-select');
			if (fontSelect) {
				['xsmall', 'small', 'regular', 'large', 'xlarge'].forEach(val => {
					let label = val.charAt(0).toUpperCase() + val.slice(1);
					if (val === 'xsmall') label = 'Extra Small';
					if (val === 'xlarge') label = 'Extra Large';
					const opt = new Option(label, val, val === feed.currentFontSize, val === feed.currentFontSize);
					fontSelect.add(opt);
				});
			}

			const displayModeSelect = editor.querySelector('.feed-display-mode-select');
			if (displayModeSelect) {
				['tiny', 'compact', 'detailed'].forEach(mode => {
					const label = mode.charAt(0).toUpperCase() + mode.slice(1);
					const opt = new Option(label, mode, mode === feed.currentDisplayMode, mode === feed.currentDisplayMode);
					displayModeSelect.add(opt);
				});
			}

			// Just set the value of the existing color input - don't create new elements
			const headerColorInput = editor.querySelector('.feed-header-color-input');
			if (headerColorInput) {
				if (feed.currentHeaderColor) {
					headerColorInput.value = feed.currentHeaderColor;
				} else {
					// Set default color from computed styles
					const tempHeader = document.createElement('div');
					tempHeader.className = 'freshvibes-container-header';
					document.body.appendChild(tempHeader);
					const defaultBg = window.getComputedStyle(tempHeader).backgroundColor;
					document.body.removeChild(tempHeader);

					const rgb = defaultBg.match(/\d+/g);
					if (rgb) {
						const hex = '#' + rgb.map(x => parseInt(x).toString(16).padStart(2, '0')).join('');
						headerColorInput.value = hex;
					}
				}
			}

			const maxHeightSelect = editor.querySelector('.feed-maxheight-select');
			if (maxHeightSelect) {
				['300', '400', '500', '600', '700', '800', 'unlimited', 'fit'].forEach(val => {
					let label;
					switch (val) {
						case 'unlimited':
							label = tr.unlimited || 'Unlimited';
							break;
						case 'fit':
							label = tr.fit_to_content || 'Fit to content';
							break;
						default:
							label = val + 'px';
					}
					const opt = new Option(label, val, val === feed.currentMaxHeight, val === feed.currentMaxHeight);
					maxHeightSelect.add(opt);
				});
			}

			// Add move-to options if there are other tabs
			if (!isCategoryMode) {
				const otherTabs = state.layout.filter(t => t.id !== sourceTabId);
				if (otherTabs.length > 0) {
					const moveDiv = document.createElement('div');
					moveDiv.className = 'feed-move-to';
					const lbl = document.createElement('label');
					lbl.textContent = tr.move_to || 'Move to:';
					moveDiv.appendChild(lbl);
					const ul = document.createElement('ul');
					ul.className = 'feed-move-to-list';
					otherTabs.forEach(tab => {
						const li = document.createElement('li');
						const button = document.createElement('button');
						button.type = 'button';
						button.dataset.targetTabId = tab.id;
						button.textContent = tab.name;
						button.setAttribute('aria-label', `Move feed to tab: ${tab.name}`);
						li.appendChild(button);
						ul.appendChild(li);
					});
					moveDiv.appendChild(ul);
					editor.appendChild(moveDiv);
				}
			}
		}
		return container;
	}

	function showEntryModal(entry, li) {
		if (!entryModal) return;

		document.body.classList.add('modal-open');

		const feedData = state.feeds[entry.feedId];

		if (modalTitle) modalTitle.textContent = entry.title || '';

		if (modalFeed && feedData) {
			modalFeed.href = feedUrl.replace('f_', 'f_' + feedData.id);
			if (modalFeedIcon) {
				modalFeedIcon.classList.toggle('hidden', !feedData.favicon);
				if (feedData.favicon) modalFeedIcon.src = feedData.favicon;
			}
			if (modalFeedName) modalFeedName.textContent = feedData.name || '';
		}

		if (modalAuthorWrapper && modalAuthor && modalAuthorPrefix) {
			const cleanAuthor = entry.author ? entry.author.replace(/^[;:\s]+/, '').trim() : '';
			modalAuthorWrapper.classList.toggle('hidden', !cleanAuthor);
			if (cleanAuthor) {
				modalAuthorPrefix.textContent = tr.by_author || 'By: ';
				modalAuthor.textContent = cleanAuthor;
				modalAuthor.href = searchAuthorUrl + '&search=' + encodeURIComponent('author:"' + cleanAuthor + '"');
			}
		}

		if (modalDate) modalDate.textContent = entry.dateShort;
		if (modalExcerpt) {
			modalExcerpt.innerHTML = entry.detailedSnippet || '';
		}
		if (modalLink) modalLink.href = entry.link || '#';

		if (modalTagsContainer) {
			const hasTags = entry.tags && entry.tags.length > 0;
			modalTagsContainer.classList.toggle('hidden', !hasTags);
			if (hasTags) {
				modalTags.innerHTML = '';
				entry.tags.forEach(tag => {
					const a = document.createElement('a');
					a.className = 'fv-modal-tag';
					a.textContent = `#${tag}`;
					a.href = searchTagUrl + '&search=' + encodeURIComponent('#' + tag);
					modalTags.appendChild(a);
				});
			}
		}

		if (modalMarkUnread) {
			modalMarkUnread.classList.remove('hidden');
			modalMarkUnread.dataset.entryId = entry.id;
			modalMarkUnread.dataset.feedId = entry.feedId;
		}

		entryModal.classList.add('active');

		if (!entry.isRead && markReadUrl) {
			const btn = li.querySelector('.entry-action-btn');
			if (btn) {
				btn.classList.add('is-read');
				btn.title = tr.mark_unread || 'Mark as unread';
			}
			entry.isRead = true;
			li.classList.add('read');

			if (feedData && feedData.nbUnread > 0) {
				feedData.nbUnread--;
				const badge = document.querySelector(`.freshvibes-container[data-feed-id="${feedData.id}"] .feed-unread-badge`);
				if (badge) {
					if (feedData.nbUnread > 0) {
						badge.textContent = feedData.nbUnread;
					} else {
						badge.remove();
					}
				}
				updateTabBadge(feedData.id);
			}

			api(markReadUrl, { id: entry.id, ajax: 1, is_read: 1 }).catch(console.error);
		}
	}

	if (entryModal) {
		entryModal.addEventListener('click', e => {
			if (e.target === entryModal || e.target.closest('.fv-modal-close')) {
				entryModal.classList.remove('active');
				document.body.classList.remove('modal-open');
			}
			if (e.target.closest('.fv-modal-mark-unread')) {
				const entryId = e.target.closest('.fv-modal-mark-unread').dataset.entryId;
				const feedId = e.target.closest('.fv-modal-mark-unread').dataset.feedId;
				if (markReadUrl && entryId && feedId) {
					fetch(markReadUrl, {
						method: 'POST',
						credentials: 'same-origin',
						headers: {
							'Content-Type': 'application/x-www-form-urlencoded',
							'X-Requested-With': 'XMLHttpRequest'
						},
						body: new URLSearchParams({
							'id': entryId,
							'is_read': 0,
							'ajax': 1,
							'_csrf': csrfToken
						})
					}).then(() => {
						const li = document.querySelector(`[data-entry-id="${entryId}"]`);
						if (li) li.classList.remove('read');
						const feedData = state.feeds[feedId];
						const entry = feedData?.entries?.find(e => String(e.id) === entryId);
						if (entry) {
							entry.isRead = false;

							// Update unread counters
							if (feedData) {
								feedData.nbUnread = (feedData.nbUnread || 0) + 1;
								const container = document.querySelector(`.freshvibes-container[data-feed-id="${feedId}"]`);
								if (container) {
									let badge = container.querySelector('.feed-unread-badge');
									if (!badge) {
										// Create badge if it doesn't exist
										const header = container.querySelector('.freshvibes-container-header');
										badge = document.createElement('span');
										badge.className = 'feed-unread-badge';
										badge.textContent = feedData.nbUnread;
										badge.title = tr.mark_all_read || 'Mark all as read';
										if (feedData.currentHeaderColor) {
											badge.style.backgroundColor = feedData.currentHeaderColor;
											badge.style.color = getContrastColor(feedData.currentHeaderColor);
											badge.style.borderColor = getContrastColor(feedData.currentHeaderColor);
										}
										header.insertBefore(badge, header.querySelector('.feed-settings'));
									} else {
										badge.textContent = feedData.nbUnread;
									}
								}
								updateTabBadge(feedId);
							}
						}
						entryModal.classList.remove('active');
					}).catch(console.error);
				}
			}
		});

		document.addEventListener('keydown', e => {
			if (e.key === 'Escape' && entryModal.classList.contains('active')) {
				entryModal.classList.remove('active');
				document.body.classList.remove('modal-open');
			}
		});
	}

	function createEntryItem(entry, feed) {
		const li = document.createElement('li');
		li.className = 'entry-item';
		li.dataset.entryId = String(entry.id);
		li.dataset.feedId = String(feed.id);
		if (entry.isRead) {
			li.classList.add('read');
		}

		// Choose the appropriate snippet based on display mode
		let snippetToUse = entry.snippet;
		if (feed.currentDisplayMode === 'compact') {
			snippetToUse = entry.compactSnippet;
		} else if (feed.currentDisplayMode === 'detailed') {
			snippetToUse = entry.detailedSnippet;
		}

		// Use the server-generated date string based on the mode
		const displayDate = dateMode === 'relative' ? entry.dateRelative : entry.dateShort;

		let entryHTML = '';
		if (feed.currentDisplayMode === 'tiny') {
			entryHTML = `
				<a class="entry-link" href="${entry.link}" target="_blank" rel="noopener noreferrer" data-entry-id="${entry.id}" data-feed-id="${feed.id}">
					<div class="entry-main">
						<span class="entry-title">${entry.title || '(No title)'}</span>
						${snippetToUse ? `<span class="entry-snippet">${snippetToUse}</span>` : ''}
					</div>
					<span class="entry-date" title="${entry.dateFull}">${displayDate}</span>
				</a>
			`;
		} else {
			entryHTML = `
				<div class="entry-wrapper">
					<div class="entry-header">
						<a class="entry-link" href="${entry.link}" target="_blank" rel="noopener noreferrer" data-entry-id="${entry.id}" data-feed-id="${feed.id}">
							<span class="entry-title">${entry.title || '(No title)'}</span>
						</a>
						<span class="entry-date" title="${entry.dateFull}">${displayDate}</span>
					</div>
					${snippetToUse ? `<div class="entry-excerpt">${snippetToUse}</div>` : ''}
				</div>
			`;
		}

		li.innerHTML = entryHTML;

		// Add action buttons using the template
		const actionsTemplate = document.getElementById('template-entry-actions');
		if (actionsTemplate) {
			const actions = actionsTemplate.content.cloneNode(true);
			const btn = actions.querySelector('.entry-action-btn');
			if (btn) {
				btn.classList.toggle('is-read', entry.isRead);
				btn.title = entry.isRead ? (tr.mark_unread || 'Mark as unread') : (tr.mark_read || 'Mark as read');
			}
			li.appendChild(actions);
		}

		return li;
	}

	// --- ACTIONS ---
	function api(url, body) {
		return fetch(url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
			body: new URLSearchParams({ ...body, '_csrf': csrfToken }),
		}).then(res => {
			if (!res.ok) throw new Error(`Network response was not ok (${res.status})`);
			return res.json();
		});
	}

	function activateTab(tabId, persist = true) {
		if (!tabId) return;
		state.activeTabId = tabId;

		tabsContainer.querySelectorAll('.freshvibes-tab').forEach(t => t.classList.toggle('active', t.dataset.tabId === tabId));
		panelsContainer.querySelectorAll('.freshvibes-panel').forEach(p => {
			const isActive = p.id === tabId;
			p.classList.toggle('active', isActive);
			if (isActive) {
				const tab = state.layout.find(t => t.id === tabId);
				if (tab) {
					renderTabContent(tab);
				}
			}
		});

		// find the slug for this tab
		const tab = state.layout.find(t => t.id === tabId);
		if (tab?.slug) {
			const url = new URL(window.location);
			url.searchParams.set('tab', tab.slug);
			window.history.replaceState(null, '', url);
		}

		if (persist) {
			api(setActiveTabUrl, { tab_id: tabId }).catch(console.error);
		}
	}

	function initializeSortable(columns) {
		if (typeof Sortable === 'undefined') return;

		columns.forEach(column => {
			// Only initialize if not already initialized
			if (column.sortable) return;

			column.sortable = new Sortable(column, {
				group: 'freshvibes-feeds',
				animation: 0,
				handle: '.freshvibes-container-header',
				delay: 300, // Add delay
				delayOnTouchOnly: true, // Only on touch devices				
				onEnd: evt => {
					const sourcePanel = evt.from.closest('.freshvibes-panel');
					const targetPanel = evt.to.closest('.freshvibes-panel');
					if (!sourcePanel || !targetPanel) return;

					const layoutData = {};
					targetPanel.querySelectorAll('.freshvibes-column').forEach(col => {
						const colId = col.dataset.columnId;
						layoutData[colId] = Array.from(col.querySelectorAll('.freshvibes-container')).map(c => c.dataset.feedId);
					});

					const tab = state.layout.find(t => t.id === targetPanel.id);
					if (tab) {
						tab.columns = layoutData;
						state.allPlacedFeedIds = new Set(state.layout.flatMap(t => Object.values(t.columns || {}).flat()).map(String));
						api(saveLayoutUrl, { layout: JSON.stringify(layoutData), tab_id: targetPanel.id }).catch(console.error);
					}
				}
			});
		});

		// Add tab sorting functionality
		if (typeof Sortable !== 'undefined' && tabsContainer && !tabsContainer.sortable && !isCategoryMode) {
			tabsContainer.sortable = new Sortable(tabsContainer, {
				animation: 150,
				draggable: '.freshvibes-tab',
				filter: '.tab-add-button',
				delay: 300, // Add 300ms delay before drag starts
				delayOnTouchOnly: true, // Only apply delay on touch devices
				onEnd: evt => {
					// Get the new order of tabs
					const newOrder = Array.from(tabsContainer.querySelectorAll('.freshvibes-tab')).map(tab => tab.dataset.tabId);

					// Reorder the layout array
					const newLayout = [];
					newOrder.forEach(tabId => {
						const tab = state.layout.find(t => t.id === tabId);
						if (tab) newLayout.push(tab);
					});

					state.layout = newLayout;

					// Save the new layout order
					api(tabActionUrl, { operation: 'reorder', tab_ids: newOrder.join(',') })
						.then(data => {
							if (data.status !== 'success') {
								// Revert on failure
								render();
							}
						})
						.catch(() => render());
				}
			});
		}
	}

	function getContrastColor(hexColor) {
		const hex = hexColor.replace('#', '');
		const r = parseInt(hex.substr(0, 2), 16);
		const g = parseInt(hex.substr(2, 2), 16);
		const b = parseInt(hex.substr(4, 2), 16);
		const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
		return luminance > 0.5 ? '#000000' : '#ffffff';
	}

	function updateTabBadge(feedId) {
		// Find which tab contains this feed
		let containingTabId = null;
		for (const tab of state.layout) {
			if (tab.columns) {
				for (const feedIds of Object.values(tab.columns)) {
					// Normalize both sides to strings for comparison
					if (feedIds.map(String).includes(String(feedId))) {
						containingTabId = tab.id;
						break;
					}
				}
			}
			if (containingTabId) break;
		}

		if (!containingTabId) return;

		// Recalculate tab unread count
		let tabUnreadCount = 0;
		const tab = state.layout.find(t => t.id === containingTabId);
		if (tab && tab.columns) {
			Object.values(tab.columns).forEach(feedIds => {
				feedIds.forEach(fId => {
					const feed = state.feeds[fId];
					if (feed && feed.nbUnread) {
						tabUnreadCount += feed.nbUnread;
					}
				});
			});
		}

		// Update tab badge
		const tabBadge = document.querySelector(`.freshvibes-tab[data-tab-id="${containingTabId}"] .tab-unread-count`);
		if (tabBadge) {
			if (tabUnreadCount > 0) {
				tabBadge.textContent = tabUnreadCount;
				tabBadge.classList.add('has-count');
			} else {
				tabBadge.classList.remove('has-count');
			}
		}
	}

	// a basic slugifier: strips accents, lower-cases, replaces runs of non-alphanumerics with '-'
	function slugify(name) {
		return name
			.normalize('NFKD')               // separate accents from letters
			.replace(/[\u0300-\u036f]/g, '') // remove the accents
			.toLowerCase()
			.replace(/[^a-z0-9]+/g, '-')     // non-alphanum → hyphen
			.replace(/^-+|-+$/g, '');        // trim leading/trailing hyphens
	}

	// ensure slugs are unique (append “-2”, “-3” if necessary)
	function assignUniqueSlugs(tabs) {
		const seen = new Map();
		tabs.forEach(tab => {
			let base = slugify(tab.name) || 'tab';
			let slug = base;
			let i = 1;
			while (seen.has(slug)) {
				i += 1;
				slug = `${base}-${i}`;
			}
			seen.set(slug, true);
			tab.slug = slug;
		});
		return tabs;
	}

	function updateSlugURL(state, tab) {
		state.layout = assignUniqueSlugs(state.layout);
		const url = new URL(window.location);
		url.searchParams.set('tab', tab.slug);
		window.history.replaceState(null, '', url);
	}

	// --- EVENT LISTENERS ---
	function setupEventListeners() {
		freshvibesView.addEventListener('click', e => {

			if (e.target.closest('.tab-settings-menu')) {
				// Handle column buttons
				const columnsButton = e.target.closest('[data-columns]');
				if (columnsButton) {
					e.stopPropagation();
					const numCols = columnsButton.dataset.columns;
					const tabId = columnsButton.closest('.freshvibes-tab').dataset.tabId;

					// Update active state immediately
					columnsButton.parentElement.querySelectorAll('button').forEach(btn => {
						btn.classList.toggle('active', btn.dataset.columns === numCols);
					});

					api(tabActionUrl, { operation: 'set_columns', tab_id: tabId, value: numCols }).then(data => {
						if (data.status === 'success') {
							state.layout = data.new_layout;
							state.allPlacedFeedIds = new Set(data.new_layout.flatMap(t => Object.values(t.columns).flat()).map(String));
							const tabData = state.layout.find(t => t.id === tabId);
							renderTabContent(tabData);
						}
					}).catch(console.error);
					return;
				}

				// Prevent closing when clicking color inputs
				if (e.target.matches('input[type="color"]')) {
					e.stopPropagation();
					return;
				}

				const deleteBtn = e.target.closest('.tab-action-delete');
				if (deleteBtn) {
					if (isCategoryMode) return;
					e.stopPropagation();
					const tabEl = deleteBtn.closest('.freshvibes-tab');
					const tabId = tabEl.dataset.tabId;
					const confirmDelete = freshvibesView.dataset.xextensionFreshvibesviewConfirmTabDelete !== '0';

					const performDelete = () => {
						api(tabActionUrl, { operation: 'delete', tab_id: tabId })
							.then(data => {
								if (data.status === 'success') {
									state.layout = assignUniqueSlugs(data.new_layout);
									state.allPlacedFeedIds = new Set(
										data.new_layout.flatMap(t => Object.values(t.columns).flat()).map(String)
									);
									if (state.activeTabId === tabId) {
										state.activeTabId = state.layout[0]?.id || null;
									}
									render();
									if (state.activeTabId) activateTab(state.activeTabId);
								}
							})
							.catch(console.error);
					};

					if (confirmDelete) {
						if (confirm(tr.confirm_delete_tab || 'Are you sure you want to delete this tab? Feeds on it will be moved to your first tab.')) {
							performDelete();
						}
					} else {
						performDelete();
					}
					return;
				}

				// — Handle Reset Tab Background Color —
				const resetBtn = e.target.closest('.color-reset');
				if (resetBtn) {
					const colorInput = resetBtn.previousElementSibling;
					if (colorInput.classList.contains('tab-bg-color-input')) {
						e.stopPropagation();
						const tabEl = resetBtn.closest('.freshvibes-tab');
						const tabId = tabEl.dataset.tabId;

						// Clear ALL styles including CSS custom properties
						tabEl.style.removeProperty('--tab-bg-color');
						tabEl.style.removeProperty('--tab-font-color');
						tabEl.classList.remove('has-custom-color');


						api(tabActionUrl, { operation: 'set_colors', tab_id: tabId, bg_color: '', font_color: '' })
							.then(data => {
								if (data.status === 'success') {
									const tabData = state.layout.find(t => t.id === tabId);
									if (tabData) {
										tabData.bg_color = '';
										tabData.font_color = '';
									}
									// Reset color‐picker to default computed style
									const tempTab = document.createElement('div');
									tempTab.className = 'freshvibes-tab';
									document.body.appendChild(tempTab);
									const defaultBg = window.getComputedStyle(tempTab).backgroundColor;
									document.body.removeChild(tempTab);

									const rgb = defaultBg.match(/\d+/g);
									if (rgb) {
										const hex = '#' + rgb.map(x => parseInt(x).toString(16).padStart(2, '0')).join('');
										colorInput.value = hex;
									}
								}
							})
							.catch(console.error);
					}
					return;
				}

				// Don't close menu for other clicks inside
				e.stopPropagation();
				return;
			}

			if (e.target.closest('.tab-add-button')) {
				if (isCategoryMode) return;
				api(tabActionUrl, { operation: 'add' }).then(data => {
					if (data.status === 'success') {
						state.layout.push(data.new_tab);
						updateSlugURL(state, data.new_tab);
						render();

					}
				}).catch(console.error);
				return;
			}

			const tabLink = e.target.closest('.freshvibes-tab');
			if (tabLink && !e.target.closest('.tab-settings-button, .tab-settings-menu, .tab-unread-count')) {
				activateTab(tabLink.dataset.tabId);
				return;
			}

			if (e.target.closest('.tab-settings-button')) {
				const button = e.target.closest('.tab-settings-button');
				const menu = button.nextElementSibling;

				document.querySelectorAll('.tab-settings-menu.active').forEach(m => {
					if (m !== menu) {
						m.classList.remove('active');
					}
				});

				menu.classList.toggle('active');
				e.stopPropagation();
				return;
			}

			const columnsButton = e.target.closest('[data-columns]');
			if (columnsButton) {
				const numCols = columnsButton.dataset.columns;
				const tabId = columnsButton.closest('.freshvibes-tab').dataset.tabId;
				const tabLink = tabsContainer.querySelector(`[data-tab-id="${tabId}"]`);

				// Update active state immediately
				columnsButton.parentElement.querySelectorAll('button').forEach(btn => {
					btn.classList.toggle('active', btn.dataset.columns === numCols);
				});

				api(tabActionUrl, { operation: 'set_columns', tab_id: tabId, value: numCols }).then(data => {
					if (data.status === 'success') {
						state.layout = assignUniqueSlugs(data.new_layout);
						state.allPlacedFeedIds = new Set(data.new_layout.flatMap(t => Object.values(t.columns).flat()).map(String));
						const tabData = state.layout.find(t => t.id === tabId);
						renderTabContent(tabData);
					}
				}).catch(console.error);
				return;
			}

			if (e.target.closest('.feed-settings-button')) {
				const editor = e.target.closest('.feed-settings-button').nextElementSibling;
				editor.classList.toggle('active');
				e.stopPropagation();
				return;
			}

			const moveButton = e.target.closest('.feed-move-to-list button');
			if (moveButton) {
				const container = moveButton.closest('.freshvibes-container');
				const feedId = container.dataset.feedId;
				const sourceTabId = container.dataset.sourceTabId;
				const targetTabId = moveButton.dataset.targetTabId;

				if (!moveFeedUrl) {
					console.error('FreshVibesView: moveFeedUrl is not defined in the dataset. Cannot move feed.');
					return;
				}

				// Close the settings menu
				container.querySelector('.feed-settings-editor').classList.remove('active');

				api(moveFeedUrl, { feed_id: feedId, source_tab_id: sourceTabId, target_tab_id: targetTabId })
					.then(data => {
						if (data.status === 'success' && data.new_layout) {
							// Update the entire layout with the server response
							state.layout = assignUniqueSlugs(data.new_layout);
							state.allPlacedFeedIds = new Set(data.new_layout.flatMap(t =>
								Object.values(t.columns || {}).flat()
							).map(String));

							// Update tabs to show new unread counts
							renderTabs();

							// Re-render current tab content (don't switch tabs)
							const currentTab = state.layout.find(t => t.id === state.activeTabId);
							if (currentTab) {
								renderTabContent(currentTab);
							}
						}
					}).catch(error => {
						console.error('Error moving feed:', error);
					});
				return;
			}

			if (e.target.closest('.feed-settings-save')) {
				const editor = e.target.closest('.feed-settings-editor');
				const container = editor.closest('.freshvibes-container');
				const feedId = container.dataset.feedId;
				const limit = editor.querySelector('.feed-limit-select').value;
				const fontSize = editor.querySelector('.feed-fontsize-select').value;
				const headerColorInput = editor.querySelector('.feed-header-color-input');
				const headerColor = headerColorInput ? headerColorInput.value : '';
				const maxHeight = editor.querySelector('.feed-maxheight-select').value;
				const displayModeSelect = editor.querySelector('.feed-display-mode-select');
				const displayMode = displayModeSelect ? displayModeSelect.value : 'tiny';

				// Apply font size immediately
				container.className = 'freshvibes-container';
				container.classList.toggle('fontsize-xsmall', fontSize === 'xsmall');
				container.classList.toggle('fontsize-small', fontSize === 'small');
				container.classList.toggle('fontsize-large', fontSize === 'large');
				container.classList.toggle('fontsize-xlarge', fontSize === 'xlarge');

				// Apply display mode immediately
				container.classList.toggle('display-compact', displayMode === 'compact');
				container.classList.toggle('display-detailed', displayMode === 'detailed');

				const contentDiv = container.querySelector('.freshvibes-container-content');
				if (contentDiv) {
					if (maxHeight === 'unlimited' || maxHeight === 'fit') {
						contentDiv.style.maxHeight = '';
					} else {
						contentDiv.style.maxHeight = maxHeight + 'px';
					}
				}

				api(saveFeedSettingsUrl, {
					feed_id: feedId,
					limit,
					font_size: fontSize,
					header_color: headerColor,
					max_height: maxHeight,
					display_mode: displayMode
				}).then(data => {
					if (data.status === 'success') {
						editor.classList.remove('active');
						const oldLimit = state.feeds[feedId].currentLimit;
						const oldDisplayMode = state.feeds[feedId].currentDisplayMode;
						state.feeds[feedId].currentLimit = parseInt(limit, 10) || limit;
						state.feeds[feedId].currentFontSize = fontSize;
						state.feeds[feedId].currentHeaderColor = headerColor;
						state.feeds[feedId].currentMaxHeight = maxHeight;
						state.feeds[feedId].currentDisplayMode = displayMode;

						// Reload if limit or display mode changes (since content changes)
						if (String(oldLimit) !== String(limit) || oldDisplayMode !== displayMode) {
							location.reload();
						}
					}
				}).catch(console.error);
				return;
			}

			if (e.target.closest('.feed-settings-cancel')) {
				e.target.closest('.feed-settings-editor').classList.remove('active');
				return;
			}

			if (e.target.closest('.feed-unread-badge')) {
				const badge = e.target.closest('.feed-unread-badge');
				const container = badge.closest('.freshvibes-container');
				const feedId = container.dataset.feedId;
				const feedData = state.feeds[feedId];
				const shouldConfirm = confirmMarkRead !== '0';

				const performMarkRead = () => {
					api(markFeedReadUrl, { feed_id: feedId }).then(data => {
						if (data.status === 'success') {
							badge.remove();
							feedData.nbUnread = 0;
							container.querySelectorAll('.entry-item:not(.read)').forEach(li => li.classList.add('read'));
							feedData.entries.forEach(entry => entry.isRead = true);
							updateTabBadge(feedId);
						}
					}).catch(console.error);
				};

				if (shouldConfirm) {
					if (confirm(tr.confirm_mark_all_read || `Mark all ${feedData.nbUnread} entries in "${feedData.name}" as read?`)) {
						performMarkRead();
					}
				} else {
					performMarkRead();
				}
				e.stopPropagation();
				return;
			}

			if (!e.target.closest('.tab-settings-menu')) {
				document.querySelectorAll('.tab-settings-menu.active').forEach(m => m.classList.remove('active'));
			}

			if (!e.target.closest('.feed-settings-editor')) {
				document.querySelectorAll('.feed-settings-editor.active').forEach(ed => ed.classList.remove('active'));
			}

			if (e.target.closest('.tab-unread-count')) {
				const badge = e.target.closest('.tab-unread-count');
				const tabEl = badge.closest('.freshvibes-tab');
				const tabId = tabEl.dataset.tabId;
				const tabData = state.layout.find(t => t.id === tabId);
				const shouldConfirm = confirmMarkRead === '1';

				if (tabData && tabData.unread_count > 0) {
					const performMarkRead = () => {
						api(markTabReadUrl, { tab_id: tabId }).then(data => {
							if (data.status === 'success') {
								badge.textContent = '0';
								badge.style.display = 'none';
								tabData.unread_count = 0;
								if (state.activeTabId === tabId) {
									document.querySelectorAll('.freshvibes-container').forEach(container => {
										const unreadBadge = container.querySelector('.feed-unread-badge');
										if (unreadBadge) unreadBadge.remove();
										container.querySelectorAll('.entry-item:not(.read)').forEach(li => li.classList.add('read'));
									});
								}
							}
						}).catch(console.error);
					};

					if (shouldConfirm) {
						if (confirm(tr.confirm_mark_tab_read || `Mark all entries in "${tabData.name}" as read?`)) {
							performMarkRead();
						}
					} else {
						performMarkRead();
					}
				}
				e.stopPropagation();
				e.preventDefault();
				return;
			}

			if (e.target.closest('.color-reset')) {
				const resetBtn = e.target.closest('.color-reset');
				const colorInput = resetBtn.previousElementSibling;

				if (colorInput.classList.contains('tab-bg-color-input')) {
					e.stopPropagation();
					const tabEl = resetBtn.closest('.freshvibes-tab');
					const tabId = tabEl.dataset.tabId;

					tabEl.style.removeProperty('--tab-bg-color');
					tabEl.style.removeProperty('--tab-font-color');

					// Save: Send empty values to the server to signify a reset
					api(tabActionUrl, { operation: 'set_colors', tab_id: tabId, bg_color: '', font_color: '' })
						.then(data => {
							if (data.status === 'success') {
								const tabData = state.layout.find(t => t.id === tabId);
								if (tabData) {
									tabData.bg_color = '';
									tabData.font_color = '';
								}
								// Reset color-picker to default computed style
								const tempTab = document.createElement('div');
								tempTab.className = 'freshvibes-tab';
								document.body.appendChild(tempTab);
								const defaultBg = window.getComputedStyle(tempTab).backgroundColor;
								document.body.removeChild(tempTab);

								const rgb = defaultBg.match(/\d+/g);
								if (rgb) {
									const hex = '#' + rgb.map(x => parseInt(x).toString(16).padStart(2, '0')).join('');
									colorInput.value = hex;
								}
							}
						})
						.catch(console.error);

				} else if (colorInput.classList.contains('feed-header-color-input')) {
					const container = resetBtn.closest('.freshvibes-container');
					const feedId = container.dataset.feedId;
					const header = container.querySelector('.freshvibes-container-header');

					// Preview: Remove custom colors immediately
					header.style.removeProperty('--header-bg-color');
					header.style.removeProperty('--header-font-color');
					header.classList.remove('has-custom-color');

					// Get current settings from state to send a complete request
					const currentFeedState = state.feeds[feedId];

					// Save: send all parameters to the backend
					api(saveFeedSettingsUrl, {
						feed_id: feedId,
						limit: currentFeedState.currentLimit,
						font_size: currentFeedState.currentFontSize,
						max_height: currentFeedState.currentMaxHeight,
						display_mode: currentFeedState.currentDisplayMode,
						header_color: '' // Reset the color
					}).then(data => {
						if (data.status === 'success') {
							// Update state and UI on success
							state.feeds[feedId].currentHeaderColor = '';
							colorInput.value = '#f0f0f0'; // A default light gray
						}
					}).catch(console.error);
				}
				return;
			}
		});

		freshvibesView.addEventListener('click', e => {
			const actionBtn = e.target.closest('.entry-action-btn');
			if (actionBtn) {
				e.stopPropagation();
				e.preventDefault();

				const entryItem = actionBtn.closest('.entry-item');
				const feedId = entryItem.dataset.feedId;
				const entryId = entryItem.dataset.entryId;
				const feedData = state.feeds[feedId];
				const entry = feedData?.entries?.find(e => String(e.id) === entryId);

				if (!entry || !markReadUrl) return;

				const isCurrentlyRead = entry.isRead;
				const newReadState = !isCurrentlyRead;

				api(markReadUrl, { id: entryId, is_read: newReadState ? 1 : 0, ajax: 1 })
					.then(() => {
						entry.isRead = newReadState;
						entryItem.classList.toggle('read', newReadState);
						actionBtn.classList.toggle('is-read', newReadState);
						actionBtn.title = newReadState ? (tr.mark_unread || 'Mark as unread') : (tr.mark_read || 'Mark as read');

						if (feedData) {
							if (newReadState && feedData.nbUnread > 0) {
								feedData.nbUnread--;
							} else if (!newReadState) {
								feedData.nbUnread = (feedData.nbUnread || 0) + 1;
							}

							const container = document.querySelector(`.freshvibes-container[data-feed-id="${feedId}"]`);
							if (container) {
								let badge = container.querySelector('.feed-unread-badge');
								if (feedData.nbUnread > 0) {
									if (!badge) {
										const header = container.querySelector('.freshvibes-container-header');
										badge = document.createElement('span');
										badge.className = 'feed-unread-badge';
										badge.title = tr.mark_all_read || 'Mark all as read';
										header.insertBefore(badge, header.querySelector('.feed-settings'));
									}
									badge.textContent = feedData.nbUnread;
								} else if (badge) {
									badge.remove();
								}
							}
							updateTabBadge(feedId);
						}
					}).catch(console.error);

				return; // Stop further execution in the main click handler
			}
		});

		freshvibesView.addEventListener('click', e => {
			// This handler is specifically for entry links
			const entryLink = e.target.closest('.entry-link');

			// Ignore if the click is not on an entry link or is on one of the action buttons
			if (!entryLink || e.target.closest('.entry-actions')) {
				return;
			}

			const clickMode = freshvibesView.dataset.xextensionFreshvibesviewEntryClickMode || 'modal';

			// For 'external' mode, we do nothing. The browser will follow the link's href
			// and `target="_blank"` will correctly open it in a new tab.
			if (clickMode === 'external') {
				const entryItem = entryLink.closest('.entry-item');
				const feedId = entryItem.dataset.feedId;
				const entryId = entryItem.dataset.entryId;
				const feedData = state.feeds[feedId];
				const entry = feedData?.entries?.find(e => String(e.id) === entryId);

				if (!entry || entry.isRead || !markReadUrl) {
					return; // Do nothing if entry is not found, already read, or URL is missing
				}

				// Mark as read via API
				fetch(markReadUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded',
						'X-Requested-With': 'XMLHttpRequest'
					},
					body: new URLSearchParams({
						'id': entryId,
						'is_read': 1,
						'ajax': 1,
						'_csrf': csrfToken
					})
				}).then(res => {
					if (!res.ok) return;

					entry.isRead = true;
					entryItem.classList.add('read');

					// Update unread counters
					if (feedData && feedData.nbUnread > 0) {
						feedData.nbUnread--;
						const badge = document.querySelector(
							`.freshvibes-container[data-feed-id="${feedData.id}"] .feed-unread-badge`
						);
						if (badge) {
							if (feedData.nbUnread > 0) {
								badge.textContent = feedData.nbUnread;
							} else {
								badge.remove();
							}
						}
						updateTabBadge(feedData.id);
					}
				}).catch(console.error);
				return;
			}

			// For 'modal' mode a left-click should open the modal.
			// We prevent the default link navigation and trigger the modal.
			if (clickMode === 'modal') {
				e.preventDefault();

				const feedId = entryLink.dataset.feedId;
				const entryId = entryLink.dataset.entryId;
				const feedData = state.feeds[feedId];
				const entry = feedData?.entries?.find(en => String(en.id) === entryId);

				if (entry) {
					const li = entryLink.closest('.entry-item');
					showEntryModal(entry, li);
				}
			}
		}, true);

		tabsContainer.addEventListener('change', e => {
			if (e.target.classList.contains('tab-icon-input') || e.target.classList.contains('tab-icon-color-input')) {
				const tabEl = e.target.closest('.freshvibes-tab');
				if (!tabEl) return;
				const tabId = tabEl.dataset.tabId;
				const iconInput = tabEl.querySelector('.tab-icon-input');
				const colorInput = tabEl.querySelector('.tab-icon-color-input');
				const iconVal = iconInput ? iconInput.value.trim() : '';
				const colorVal = colorInput ? colorInput.value : '#000000';

				api(tabActionUrl, { operation: 'set_icon', tab_id: tabId, icon: iconVal, color: colorVal }).then(data => {
					if (data.status === 'success') {
						const iconSpan = tabEl.querySelector('.tab-icon');
						if (iconSpan) {
							iconSpan.textContent = iconVal;
							iconSpan.style.setProperty('--tab-icon-color', colorVal);
						}
						const tabData = state.layout.find(t => t.id === tabId);
						if (tabData) {
							tabData.icon = iconVal;
							tabData.icon_color = colorVal;
						}
					}
				}).catch(console.error);
			} else if (e.target.classList.contains('tab-bg-color-input')) {
				const tabEl = e.target.closest('.freshvibes-tab');
				if (!tabEl) return;
				const tabId = tabEl.dataset.tabId;
				const bgColor = e.target.value;
				const fontColor = getContrastColor(bgColor);

				api(tabActionUrl, { operation: 'set_colors', tab_id: tabId, bg_color: bgColor, font_color: fontColor })
					.then(data => {
						if (data.status === 'success') {
							const tabData = state.layout.find(t => t.id === tabId);
							if (tabData) {
								tabData.bg_color = bgColor;
								tabData.font_color = fontColor;
							}
							tabEl.style.setProperty('--tab-bg-color', bgColor);
							tabEl.style.setProperty('--tab-font-color', fontColor);
						}
					})
					.catch(console.error);
			}
		});

		tabsContainer.addEventListener('dblclick', e => {
			if (isCategoryMode) return;
			const tabNameSpan = e.target.closest('.tab-name');
			if (!tabNameSpan) return;

			const tabElement = tabNameSpan.closest('.freshvibes-tab');
			if (!tabElement) return;

			const tabId = tabElement.dataset.tabId;
			const oldName = tabNameSpan.textContent;
			const input = document.createElement('input');
			input.type = 'text';
			input.className = 'tab-name-input';
			input.value = oldName;

			let isSaving = false;
			const saveName = () => {
				if (isSaving) return;
				isSaving = true;

				const newName = input.value.trim();

				if (input.parentNode) {
					input.replaceWith(tabNameSpan);
				}

				if (newName && newName !== oldName) {
					tabNameSpan.textContent = newName;
					api(tabActionUrl, { operation: 'rename', tab_id: tabId, value: newName }).then(data => {
						if (data.status === 'success') {
							const tabInState = state.layout.find(t => t.id === tabId);
							if (tabInState) tabInState.name = newName;
							updateSlugURL(state, tabInState);
							// Update all move-to dropdown buttons with the new tab name
							document.querySelectorAll(`.feed-move-to-list button[data-target-tab-id="${tabId}"]`).forEach(button => {
								button.textContent = newName;
								button.setAttribute('aria-label', `Move feed to tab: ${newName}`);
							});
						} else {
							tabNameSpan.textContent = oldName;
						}
					}).catch(err => {
						console.error("Error renaming tab:", err);
						tabNameSpan.textContent = oldName;
					});
				} else {
					tabNameSpan.textContent = oldName;
				}
			};

			input.addEventListener('blur', saveName);
			input.addEventListener('keydown', ev => {
				if (ev.key === 'Enter') {
					ev.preventDefault();
					saveName();
				} else if (ev.key === 'Escape') {
					input.value = oldName;
					input.blur();
				}
			});

			tabNameSpan.replaceWith(input);
			input.focus();
			input.select();
		});

		tabsContainer.addEventListener('input', e => {
			if (e.target.classList.contains('tab-bg-color-input')) {
				const tabEl = e.target.closest('.freshvibes-tab');
				if (!tabEl) return;
				const bgColor = e.target.value;
				// live preview by setting CSS variables
				tabEl.style.setProperty('--tab-bg-color', bgColor);
				tabEl.style.setProperty('--tab-font-color', getContrastColor(bgColor));
				tabEl.classList.add('has-custom-color');
			}

			if (e.target.classList.contains('tab-icon-color-input')) {
				const tabEl = e.target.closest('.freshvibes-tab');
				if (!tabEl) return;
				const iconSpan = tabEl.querySelector('.tab-icon');
				if (iconSpan) {
					// Set variable for icon color
					iconSpan.style.setProperty('--tab-icon-color', e.target.value);
				}
			}
		});

		// Icon picker functionality
		const iconPicker = document.getElementById('tab-icon-picker');
		let activeIconInput = null;

		if (iconPicker) {
			tabsContainer.addEventListener('click', e => {
				if (e.target.classList.contains('tab-icon-input')) {
					e.stopPropagation();
					activeIconInput = e.target;
					const rect = e.target.getBoundingClientRect();
					iconPicker.style.left = `${rect.left}px`;
					iconPicker.style.top = `${rect.bottom + 5}px`;
					iconPicker.classList.add('active');
				}
			});
			iconPicker.addEventListener('click', e => {
				if (e.target.dataset.icon && activeIconInput) {
					activeIconInput.value = e.target.dataset.icon;
					activeIconInput.dispatchEvent(new Event('change', { bubbles: true }));
					iconPicker.classList.remove('active');
				}
			});
		}

		// Close icon picker when clicking outside
		document.addEventListener('click', e => {
			if (!e.target.closest('.tab-icon-input') && !e.target.closest('#tab-icon-picker')) {
				iconPicker?.classList.remove('active');
			}
		});

		// Add live preview for feed header colors
		freshvibesView.addEventListener('input', e => {
			if (e.target.classList.contains('feed-header-color-input')) {
				const container = e.target.closest('.freshvibes-container');
				const header = container.querySelector('.freshvibes-container-header');
				const color = e.target.value;
				// Live preview via CSS variables
				header.style.setProperty('--header-bg-color', color);
				header.style.setProperty('--header-font-color', getContrastColor(color));
				header.classList.add('has-custom-color');

			}
		});

		// Handle feed settings changes
		// Handle feed settings changes
		freshvibesView.addEventListener('change', e => {
			const feedSettingsEditor = e.target.closest('.feed-settings-editor');
			if (!feedSettingsEditor) return;

			if (e.target.matches('.feed-limit-select, .feed-fontsize-select, .feed-maxheight-select, .feed-header-color-input, .feed-display-mode-select')) {
				const container = feedSettingsEditor.closest('.freshvibes-container');
				const feedId = container.dataset.feedId;

				// --- Live Preview Logic ---
				const fontSize = feedSettingsEditor.querySelector('.feed-fontsize-select').value;
				const maxHeight = feedSettingsEditor.querySelector('.feed-maxheight-select').value;
				const displayMode = feedSettingsEditor.querySelector('.feed-display-mode-select').value;

				// Apply font size class
				container.className = 'freshvibes-container';
				container.classList.toggle('fontsize-xsmall', fontSize === 'xsmall');
				container.classList.toggle('fontsize-small', fontSize === 'small');
				container.classList.toggle('fontsize-large', fontSize === 'large');
				container.classList.toggle('fontsize-xlarge', fontSize === 'xlarge');

				// Apply display mode classes
				container.classList.toggle('display-compact', displayMode === 'compact');
				container.classList.toggle('display-detailed', displayMode === 'detailed');

				// Apply max-height
				const contentDiv = container.querySelector('.freshvibes-container-content');
				if (contentDiv) {
					contentDiv.style.maxHeight = ['unlimited', 'fit'].includes(maxHeight) ? '' : `${maxHeight}px`;
				}

				// --- Auto-save ---
				const limit = feedSettingsEditor.querySelector('.feed-limit-select').value;
				const headerColor = feedSettingsEditor.querySelector('.feed-header-color-input').value;

				// Save settings
				api(saveFeedSettingsUrl, {
					feed_id: feedId,
					limit: limit,
					font_size: fontSize,
					header_color: headerColor,
					max_height: maxHeight,
					display_mode: displayMode
				}).then(data => {
					if (data.status === 'success') {
						const feed = state.feeds[feedId];
						const oldLimit = feed.currentLimit;
						const oldDisplayMode = feed.currentDisplayMode;

						// Update state
						feed.currentLimit = isNaN(parseInt(limit, 10)) ? limit : parseInt(limit, 10);
						feed.currentFontSize = fontSize;
						feed.currentHeaderColor = headerColor;
						feed.currentMaxHeight = maxHeight;
						feed.currentDisplayMode = displayMode;

						// Reload if limit or display mode changes
						if (String(oldLimit) !== String(limit) || oldDisplayMode !== displayMode) {
							location.reload();
						}
					}
				}).catch(error => {
					console.error('Error saving feed settings:', error);
				});
			}
		});

		// Handle middle-click on entries to mark as read
		freshvibesView.addEventListener('auxclick', e => {
			if (e.button !== 1) { // We only care about the middle mouse button
				return;
			}

			const entryLink = e.target.closest('.entry-link');
			if (!entryLink) {
				return;
			}

			// Do NOT prevent default. This allows the link to open in a new tab.

			const entryItem = entryLink.closest('.entry-item');
			const feedId = entryItem.dataset.feedId;
			const entryId = entryItem.dataset.entryId;
			const feedData = state.feeds[feedId];
			const entry = feedData?.entries?.find(e => String(e.id) === entryId);

			if (!entry || entry.isRead || !markReadUrl) {
				return; // Do nothing if entry is not found, already read, or URL is missing
			}

			// Mark as read via API
			fetch(markReadUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
					'X-Requested-With': 'XMLHttpRequest'
				},
				body: new URLSearchParams({
					'id': entryId,
					'is_read': 1,
					'ajax': 1,
					'_csrf': csrfToken
				})
			}).then(res => {
				if (!res.ok) return;

				entry.isRead = true;
				entryItem.classList.add('read');

				// Update unread counters
				if (feedData && feedData.nbUnread > 0) {
					feedData.nbUnread--;
					const badge = document.querySelector(
						`.freshvibes-container[data-feed-id="${feedData.id}"] .feed-unread-badge`
					);
					if (badge) {
						if (feedData.nbUnread > 0) {
							badge.textContent = feedData.nbUnread;
						} else {
							badge.remove();
						}
					}
					updateTabBadge(feedData.id);
				}
			}).catch(console.error);
		});

		// Bulk settings modal
		const bulkSettingsBtn = document.getElementById('bulk-settings-btn');
		const bulkSettingsModal = document.getElementById('bulk-settings-modal');

		if (bulkSettingsBtn && bulkSettingsModal) {
			bulkSettingsBtn.addEventListener('click', () => {
				bulkSettingsModal.classList.add('active');
				document.body.classList.add('modal-open');
			});

			bulkSettingsModal.addEventListener('click', e => {
				if (e.target === bulkSettingsModal || e.target.closest('.fv-modal-close')) {
					bulkSettingsModal.classList.remove('active');
					document.body.classList.remove('modal-open');
				}
			});

			const feedColorInput = document.getElementById('bulk-feed-header-color');
			if (feedColorInput) {
				const tempHeader = document.createElement('div');
				tempHeader.className = 'freshvibes-container-header';
				document.body.appendChild(tempHeader);
				const defaultColor = window.getComputedStyle(tempHeader).backgroundColor;
				document.body.removeChild(tempHeader);

				const rgb = defaultColor.match(/\d+/g);
				if (rgb) {
					const hex = '#' + rgb.map(x => parseInt(x).toString(16).padStart(2, '0')).join('');
					feedColorInput.value = hex;
				}
			}

			const tabColorInput = document.getElementById('bulk-tab-bg-color');
			if (tabColorInput) {
				const tempTab = document.createElement('div');
				tempTab.className = 'freshvibes-tab';
				document.body.appendChild(tempTab);
				const defaultColor = window.getComputedStyle(tempTab).backgroundColor;
				document.body.removeChild(tempTab);

				const rgb = defaultColor.match(/\d+/g);
				if (rgb) {
					const hex = '#' + rgb.map(x => parseInt(x).toString(16).padStart(2, '0')).join('');
					tabColorInput.value = hex;
				}
			}

			// Apply bulk feed settings
			document.getElementById('apply-bulk-feed-settings')?.addEventListener('click', () => {
				const settings = {
					limit: document.getElementById('bulk-feed-limit').value,
					font_size: document.getElementById('bulk-feed-fontsize').value,
					display_mode: document.getElementById('bulk-feed-display').value,
					header_color: document.getElementById('bulk-feed-header-color').value,
					max_height: document.getElementById('bulk-feed-maxheight').value
				};

				if (confirm(tr.confirm_bulk_apply_feeds)) {
					api(bulkApplyFeedsUrl, settings)
						.then(() => {
							alert(tr.bulk_apply_success_feeds);
							location.reload();
						})
						.catch(err => {
							console.error('Error applying bulk feed settings:', err);
							alert('Error applying settings. Please try again.');
						});
				}
			});

			// Apply bulk tab settings
			document.getElementById('apply-bulk-tab-settings')?.addEventListener('click', () => {
				const settings = {
					num_columns: document.getElementById('bulk-tab-columns').value,
					bg_color: document.getElementById('bulk-tab-bg-color').value
				};

				if (confirm(tr.confirm_bulk_apply_tabs)) {
					api(bulkApplyTabsUrl, settings)
						.then(() => {
							alert(tr.bulk_apply_success_tabs);
							location.reload();
						})
						.catch(err => {
							console.error('Error applying bulk tab settings:', err);
							alert('Error applying settings. Please try again.');
						});
				}
			});

			// Reset all feed settings
			document.getElementById('reset-all-feed-settings')?.addEventListener('click', () => {
				if (confirm(tr.confirm_reset_all_feeds)) {
					api(resetFeedsUrl, {})
						.then(() => {
							alert(tr.bulk_reset_success_feeds);
							location.reload();
						})
						.catch(err => {
							console.error('Error resetting feed settings:', err);
							alert('Error resetting settings. Please try again.');
						});
				}
			});

			// Reset all tab settings
			document.getElementById('reset-all-tab-settings')?.addEventListener('click', () => {
				if (confirm(tr.confirm_reset_all_tabs)) {
					api(resetTabsUrl, {})
						.then(() => {
							alert(tr.bulk_reset_success_tabs);
							location.reload();
						})
						.catch(err => {
							console.error('Error resetting tab settings:', err);
							alert('Error resetting settings. Please try again.');
						});
				}
			});

			// Update color reset to use computed styles
			bulkSettingsModal.querySelectorAll('.color-reset').forEach(btn => {
				btn.addEventListener('click', e => {
					const targetId = e.target.dataset.target;
					const colorInput = document.getElementById(targetId);
					if (colorInput) {
						// Get default color from computed styles
						let defaultColor;
						if (targetId.includes('feed')) {
							// Create temporary element to get default feed header color
							const tempHeader = document.createElement('div');
							tempHeader.className = 'freshvibes-container-header';
							document.body.appendChild(tempHeader);
							defaultColor = window.getComputedStyle(tempHeader).backgroundColor;
							document.body.removeChild(tempHeader);
						} else {
							// Create temporary element to get default tab color
							const tempTab = document.createElement('div');
							tempTab.className = 'freshvibes-tab';
							document.body.appendChild(tempTab);
							defaultColor = window.getComputedStyle(tempTab).backgroundColor;
							document.body.removeChild(tempTab);
						}

						// Convert to hex
						const rgb = defaultColor.match(/\d+/g);
						if (rgb) {
							const hex = '#' + rgb.map(x => parseInt(x).toString(16).padStart(2, '0')).join('');
							colorInput.value = hex;
						}
					}
				});
			});
		}

	}

	// --- INITIALIZATION ---
	fetch(getLayoutUrl)
		.then(res => {
			if (!res.ok) {
				throw new Error(`HTTP error! status: ${res.status}`);
			}
			return res.json();
		})
		.then(data => {
			const feedsDataScript = document.getElementById('feeds-data-script');
			if (!feedsDataScript || !feedsDataScript.textContent) {
				throw new Error('Feeds data script is missing or empty.');
			}

			// Set the primary state objects from the data
			state.layout = data.layout || [];
			state.activeTabId = data.active_tab_id;
			state.feeds = JSON.parse(feedsDataScript.textContent);

			state.allPlacedFeedIds = new Set(state.layout.flatMap(t => Object.values(t.columns).flat()).map(String));

			// Clean up the temporary script tag
			document.getElementById('feeds-data-script').remove();

			state.layout = assignUniqueSlugs(state.layout);

			// check URL for “?tab=some-slug”
			const params = new URLSearchParams(window.location.search);
			const slug = params.get('tab');
			if (slug) {
				const match = state.layout.find(t => t.slug === slug);
				if (match) state.activeTabId = match.id;
			}

			// Render the fully-initialized dashboard with the correct state
			render();
			setupEventListeners();
			setupAutoRefresh();
		})
		.catch(error => {
			console.error('FreshVibesView: Could not initialize.', error);
			freshvibesView.innerHTML = '<p>' + (tr.error_dashboard_init || 'Error loading dashboard. Please check the console and try again.') + '</p>';
		});
}