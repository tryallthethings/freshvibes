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
		xextensionFreshvibesviewMode: viewMode = ''
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

	// --- RENDER FUNCTIONS ---
	function render() {
		renderTabs();
		renderPanels();
		activateTab(state.activeTabId || state.layout[0]?.id, false);
	}

	function renderTabs() {
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
				unreadBadge.style.display = '';
				// Apply contrast color for custom backgrounds
				if (tab.bg_color) {
					unreadBadge.style.backgroundColor = tab.bg_color;
					unreadBadge.style.color = getContrastColor(tab.bg_color);
					unreadBadge.style.borderColor = getContrastColor(tab.bg_color);
				}
			}

			tabsContainer.appendChild(link);
		});
		if (!isCategoryMode) {
			const addButton = document.createElement('button');
			addButton.type = 'button';
			addButton.className = 'tab-add-button';
			addButton.textContent = '+';
			addButton.ariaLabel = tr.add_tab || 'Add new tab';
			tabsContainer.appendChild(addButton);
		}
	}

	function renderPanels() {
		panelsContainer.innerHTML = '';
		state.layout.forEach(tab => panelsContainer.appendChild(createTabPanel(tab)));
	}

	function createTabLink(tab) {
		const link = templates.tabLink.content.cloneNode(true).firstElementChild;
		link.dataset.tabId = tab.id;

		// Apply saved colors
		if (tab.bg_color) {
			link.style.backgroundColor = tab.bg_color;
			link.style.color = tab.font_color || getContrastColor(tab.bg_color);
		}

		const iconSpan = link.querySelector('.tab-icon');
		if (iconSpan) {
			iconSpan.textContent = tab.icon || '';
			// Use data attribute instead of inline style
			if (tab.icon_color) {
				iconSpan.style.color = tab.icon_color;
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
				delBtn.style.display = 'none';
			}
		}

		// Set active column button
		const columnButtons = link.querySelectorAll('.columns-selector button');
		columnButtons.forEach(btn => {
			btn.classList.toggle('active', parseInt(btn.dataset.columns) === tab.num_columns);
		});

		// Apply tab colors using data attributes
		if (tab.bg_color) {
			link.style.setProperty('--tab-bg-color', tab.bg_color);
		}
		if (tab.font_color) {
			link.style.setProperty('--tab-font-color', tab.font_color);
		}

		// Set background color input value
		const bgColorInput = link.querySelector('.tab-bg-color-input');
		if (bgColorInput && tab.bg_color) {
			bgColorInput.value = tab.bg_color;
		}

		// Show unread count
		const unreadCount = link.querySelector('.tab-unread-count');
		if (unreadCount && tab.unread_count > 0) {
			unreadCount.textContent = tab.unread_count;
			unreadCount.classList.remove('hidden');
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
			// Apply header color if set
			if (feed.currentHeaderColor) {
				headerElement.style.backgroundColor = feed.currentHeaderColor;
				headerElement.style.color = getContrastColor(feed.currentHeaderColor);
			}

			if (feed.nbUnread > 0) {
				const unreadBadge = document.createElement('span');
				unreadBadge.className = 'feed-unread-badge';
				unreadBadge.textContent = feed.nbUnread;
				unreadBadge.title = tr.mark_all_read || 'Mark all as read';

				// Apply contrast color if header has custom color
				if (feed.currentHeaderColor) {
					unreadBadge.style.backgroundColor = feed.currentHeaderColor;
					unreadBadge.style.color = getContrastColor(feed.currentHeaderColor);
					unreadBadge.style.borderColor = getContrastColor(feed.currentHeaderColor);
				}

				headerElement.insertBefore(unreadBadge, headerElement.querySelector('.feed-settings'));
			}
		}

		const contentDiv = container.querySelector('.freshvibes-container-content');
		if (contentDiv) {
			contentDiv.innerHTML = '';
			if (feed.entries && Array.isArray(feed.entries) && feed.entries.length > 0 && !feed.entries.error) {
				const ul = document.createElement('ul');
				feed.entries.forEach(entry => {
					const li = document.createElement('li');
					li.className = 'entry-item';
					li.dataset.entryId = String(entry.id);
					li.dataset.feedId = String(feed.id);
					if (entry.isRead) li.classList.add('read');

					const main = document.createElement('div');
					main.className = 'entry-main';

					const a = document.createElement('span');
					a.className = 'entry-title';
					a.appendChild(document.createTextNode(entry.title || '(No title)'));
					main.appendChild(a);

					if (entry.snippet) {
						const snippet = document.createElement('span');
						snippet.className = 'entry-snippet';
						snippet.textContent = entry.snippet;
						main.appendChild(snippet);
					}

					// Add action buttons
					const actions = document.createElement('div');
					actions.className = 'entry-actions';

					const readBtn = document.createElement('button');
					readBtn.type = 'button';
					readBtn.className = 'entry-action-btn';
					readBtn.dataset.action = 'toggle';
					readBtn.innerHTML = entry.isRead
						? `<span class="action-icon">${tr.icon_unread || '○'}</span>`
						: `<span class="action-icon">${tr.icon_read || '●'}</span>`;
					readBtn.title = entry.isRead ? (tr.mark_unread || 'Mark as unread') : (tr.mark_read || 'Mark as read');
					actions.appendChild(readBtn);

					const date = document.createElement('span');
					date.className = 'entry-date';
					date.textContent = entry.dateShort;
					date.setAttribute('title', entry.dateFull);

					li.appendChild(main);
					li.appendChild(actions);
					li.appendChild(date);
					ul.appendChild(li);
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
				[5, 10, 15, 20, 25, 30, 40, 50].forEach(val => {
					const opt = new Option(val, val, val === feed.currentLimit, val === feed.currentLimit);
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

			// Just set the value of the existing color input - don't create new elements
			const headerColorInput = editor.querySelector('.feed-header-color-input');
			if (headerColorInput && feed.currentHeaderColor) {
				headerColorInput.value = feed.currentHeaderColor;
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

		const feedData = state.feeds[entry.feedId];

		if (modalTitle) modalTitle.textContent = entry.title || '';

		if (modalFeed && feedData) {
			modalFeed.href = feedUrl.replace('f_', 'f_' + feedData.id);
			if (modalFeedIcon) {
				if (feedData.favicon) {
					modalFeedIcon.src = feedData.favicon;
					modalFeedIcon.style.display = '';
				} else {
					modalFeedIcon.style.display = 'none';
				}
			}
			if (modalFeedName) modalFeedName.textContent = feedData.name || '';
		}

		if (modalAuthorWrapper && modalAuthor && modalAuthorPrefix) {
			if (entry.author) {
				// Clean the author - FreshRSS sometimes includes semicolons
				const cleanAuthor = entry.author.replace(/^[;:\s]+/, '').trim();
				modalAuthorPrefix.textContent = tr.by_author || 'By: ';
				modalAuthor.textContent = cleanAuthor;
				modalAuthor.href = searchAuthorUrl + '&search=' + encodeURIComponent('author:"' + cleanAuthor + '"');
				modalAuthorWrapper.style.display = '';
			} else {
				modalAuthorWrapper.style.display = 'none';
			}
		}

		if (modalDate) modalDate.textContent = entry.dateShort;
		if (modalExcerpt) {
			// Use innerHTML to preserve links
			modalExcerpt.innerHTML = entry.excerpt || entry.snippet || '';
		}
		if (modalLink) modalLink.href = entry.link || '#';

		if (modalTagsContainer) {
			if (entry.tags && entry.tags.length > 0) {
				modalTagsContainer.style.display = '';
				modalTags.innerHTML = '';
				entry.tags.forEach(tag => {
					const a = document.createElement('a');
					a.className = 'fv-modal-tag';
					a.textContent = `#${tag}`;
					a.href = searchTagUrl + '&search=' + encodeURIComponent('#' + tag);
					modalTags.appendChild(a);
				});
			} else {
				modalTagsContainer.style.display = 'none';
			}
		}

		if (modalMarkUnread) {
			modalMarkUnread.style.display = 'inline-flex';
			modalMarkUnread.dataset.entryId = entry.id;
			modalMarkUnread.dataset.feedId = entry.feedId;
		}

		entryModal.classList.add('active');

		if (!entry.isRead && markReadUrl) {
			entry.isRead = true;
			li.classList.add('read');

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

			fetch(markReadUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
					'X-Requested-With': 'XMLHttpRequest'
				},
				body: new URLSearchParams({
					'id': entry.id,
					'ajax': 1,
					'_csrf': csrfToken
				})
			}).catch(console.error);
		}
	}

	if (entryModal) {
		entryModal.addEventListener('click', e => {
			if (e.target === entryModal || e.target.closest('.fv-modal-close')) {
				entryModal.classList.remove('active');
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
			}
		});
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
				tabBadge.style.display = '';
			} else {
				tabBadge.style.display = 'none';
			}
		}
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
									state.layout = data.new_layout;
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
						tabEl.style.removeProperty('background-color');
						tabEl.style.removeProperty('color');
						tabEl.style.removeProperty('--tab-bg-color');
						tabEl.style.removeProperty('--tab-font-color');

						const badge = tabEl.querySelector('.tab-unread-count');
						if (badge) {
							badge.style.removeProperty('background-color');
							badge.style.removeProperty('color');
							badge.style.removeProperty('border-color');
						}

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
						state.layout = data.new_layout;
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
							state.layout = data.new_layout;
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

				// Apply font size immediately
				container.className = 'freshvibes-container';
				container.classList.toggle('fontsize-xsmall', fontSize === 'xsmall');
				container.classList.toggle('fontsize-small', fontSize === 'small');
				container.classList.toggle('fontsize-large', fontSize === 'large');
				container.classList.toggle('fontsize-xlarge', fontSize === 'xlarge');

				api(saveFeedSettingsUrl, { feed_id: feedId, limit, font_size: fontSize, header_color: headerColor }).then(data => {
					if (data.status === 'success') {
						editor.classList.remove('active');
						const oldLimit = state.feeds[feedId].currentLimit;
						state.feeds[feedId].currentLimit = parseInt(limit, 10);
						state.feeds[feedId].currentFontSize = fontSize;
						state.feeds[feedId].currentHeaderColor = headerColor;

						if (oldLimit !== parseInt(limit, 10)) {
							location.reload();
						} else {
							// Header color is already applied by the input event handler
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

				if (confirm(tr.confirm_mark_all_read || `Mark all ${feedData.nbUnread} entries in "${feedData.name}" as read?`)) {
					api(markFeedReadUrl, { feed_id: feedId }).then(data => {
						if (data.status === 'success') {
							badge.remove();
							feedData.nbUnread = 0;
							container.querySelectorAll('.entry-item:not(.read)').forEach(li => li.classList.add('read'));
							feedData.entries.forEach(entry => entry.isRead = true);
							updateTabBadge(feedId);
						}
					}).catch(console.error);
				}
				e.stopPropagation();
				return;
			}

			const entryItem = e.target.closest('.entry-item');
			if (entryItem && !e.target.closest('a') && !e.target.closest('.entry-actions')) {
				const feedId = entryItem.dataset.feedId;
				const entryId = entryItem.dataset.entryId;
				const feedData = state.feeds[feedId];
				const entry = feedData?.entries?.find(en => String(en.id) === entryId);

				if (entry) {
					const clickMode = freshvibesView.dataset.xextensionFreshvibesviewEntryClickMode || 'modal';
					const entryUrl = freshvibesView.dataset.xextensionFreshvibesviewEntryUrl || '';

					if (clickMode === 'modal') {
						showEntryModal(entry, entryItem);
					} else if (clickMode === 'freshrss' && entryUrl) {
						const url = entryUrl + entryId;
						if (e.button === 1) { // Middle click
							window.open(url, '_blank');
						} else {
							window.location.href = url;
						}
					} else if (clickMode === 'external' && entry.link) {
						// Mark as read before opening
						if (!entry.isRead && markReadUrl) {
							entry.isRead = true;
							entryItem.classList.add('read');

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

							fetch(markReadUrl, {
								method: 'POST',
								credentials: 'same-origin',
								headers: {
									'Content-Type': 'application/x-www-form-urlencoded',
									'X-Requested-With': 'XMLHttpRequest'
								},
								body: new URLSearchParams({
									'id': entry.id,
									'ajax': 1,
									'_csrf': csrfToken
								})
							}).catch(console.error);
						}

						window.open(entry.link, '_blank');
					}
					e.preventDefault();
					return;
				}
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

				if (tabData && tabData.unread_count > 0) {
					if (confirm(tr.confirm_mark_tab_read || `Mark all entries in "${tabData.name}" as read?`)) {
						api(markTabReadUrl, { tab_id: tabId }).then(data => {
							if (data.status === 'success') {
								badge.textContent = '0';
								badge.style.display = 'none';
								tabData.unread_count = 0;
								// Update all feeds in this tab
								if (state.activeTabId === tabId) {
									document.querySelectorAll('.freshvibes-container').forEach(container => {
										const unreadBadge = container.querySelector('.feed-unread-badge');
										if (unreadBadge) unreadBadge.remove();
										container.querySelectorAll('.entry-item:not(.read)').forEach(li => li.classList.add('read'));
									});
								}
							}
						}).catch(console.error);
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
					const tabEl = resetBtn.closest('.freshvibes-tab');
					if (!tabEl) return;
					const tabId = tabEl.dataset.tabId;

					// Get the computed default color
					const tempTab = document.createElement('div');
					tempTab.className = 'freshvibes-tab';
					document.body.appendChild(tempTab);
					const defaultBgColor = window.getComputedStyle(tempTab).backgroundColor;
					const defaultColor = window.getComputedStyle(tempTab).color;
					document.body.removeChild(tempTab);

					// Apply default colors immediately
					tabEl.style.backgroundColor = '';
					tabEl.style.color = '';

					api(tabActionUrl, { operation: 'set_colors', tab_id: tabId, bg_color: '', font_color: '' }).then(data => {
						if (data.status === 'success') {
							const tabData = state.layout.find(t => t.id === tabId);
							if (tabData) {
								tabData.bg_color = '';
								tabData.font_color = '';
							}
							// Set the color input to the computed default
							const rgb = defaultBgColor.match(/\d+/g);
							if (rgb) {
								const hex = '#' + rgb.map(x => parseInt(x).toString(16).padStart(2, '0')).join('');
								colorInput.value = hex;
							}
						}
					}).catch(console.error);
				} else if (colorInput.classList.contains('feed-header-color-input')) {
					const container = resetBtn.closest('.freshvibes-container');
					const feedId = container.dataset.feedId;
					const header = container.querySelector('.freshvibes-container-header');

					// Remove custom colors immediately
					header.style.backgroundColor = '';
					header.style.color = '';

					// Reset badge colors
					const unreadBadge = header.querySelector('.feed-unread-badge');
					if (unreadBadge) {
						unreadBadge.style.removeProperty('background-color');
						unreadBadge.style.removeProperty('color');
						unreadBadge.style.removeProperty('border-color');
					}

					// Reset settings button color
					const settingsBtn = header.querySelector('.feed-settings-button');
					if (settingsBtn) {
						settingsBtn.style.removeProperty('color');
					}

					api(saveFeedSettingsUrl, {
						feed_id: feedId,
						limit: state.feeds[feedId].currentLimit,
						font_size: state.feeds[feedId].currentFontSize,
						header_color: ''
					}).then(data => {
						if (data.status === 'success') {
							state.feeds[feedId].currentHeaderColor = '';
							colorInput.value = '#f0f0f0'; // or get computed style
						}
					}).catch(console.error);
				}

				return;
			}

			if (e.target.closest('.entry-action-btn')) {
				e.stopPropagation();
				e.preventDefault();

				const btn = e.target.closest('.entry-action-btn');
				const entryItem = btn.closest('.entry-item');
				const feedId = entryItem.dataset.feedId;
				const entryId = entryItem.dataset.entryId;
				const feedData = state.feeds[feedId];
				const entry = feedData?.entries?.find(e => String(e.id) === entryId);

				if (!entry || !markReadUrl) return;

				const isCurrentlyRead = entry.isRead;
				const newReadState = !isCurrentlyRead;

				fetch(markReadUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded',
						'X-Requested-With': 'XMLHttpRequest'
					},
					body: new URLSearchParams({
						'id': entryId,
						'is_read': newReadState ? 1 : 0,
						'ajax': 1,
						'_csrf': csrfToken
					})
				}).then(() => {
					entry.isRead = newReadState;
					entryItem.classList.toggle('read', newReadState);

					if (feedData) {
						if (newReadState && feedData.nbUnread > 0) {
							feedData.nbUnread--;
						} else if (!newReadState) {
							feedData.nbUnread = (feedData.nbUnread || 0) + 1;
						}

						// Update feed badge
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

					// Update button
					btn.innerHTML = newReadState
						? `<span class="action-icon">${tr.icon_unread || '○'}</span>`
						: `<span class="action-icon">${tr.icon_read || '●'}</span>`;
					btn.title = newReadState ? (tr.mark_unread || 'Mark as unread') : (tr.mark_read || 'Mark as read');
				}).catch(console.error);

				return;
			}

		});

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
							iconSpan.style.color = colorVal;
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

				api(tabActionUrl, { operation: 'set_colors', tab_id: tabId, bg_color: bgColor })
					.then(data => {
						if (data.status === 'success') {
							const tabData = state.layout.find(t => t.id === tabId);
							if (tabData) tabData.bg_color = bgColor;

							// ← new: reapply styles after save
							tabEl.style.backgroundColor = bgColor;
							tabEl.style.color = getContrastColor(bgColor);
							const badge = tabEl.querySelector('.tab-unread-count');
							if (badge) {
								badge.style.backgroundColor = bgColor;
								badge.style.color = getContrastColor(bgColor);
								badge.style.borderColor = getContrastColor(bgColor);
							}
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
				// live preview
				tabEl.style.backgroundColor = bgColor;
				tabEl.style.color = getContrastColor(bgColor);

				// ← new: update the badge too
				const badge = tabEl.querySelector('.tab-unread-count');
				if (badge) {
					badge.style.backgroundColor = bgColor;
					badge.style.color = getContrastColor(bgColor);
					badge.style.borderColor = getContrastColor(bgColor);
				}
			}

			if (e.target.classList.contains('tab-icon-color-input')) {
				const tabEl = e.target.closest('.freshvibes-tab');
				if (!tabEl) return;
				const iconSpan = tabEl.querySelector('.tab-icon');
				if (iconSpan) {
					iconSpan.style.color = e.target.value;
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
				// Header background/text
				header.style.backgroundColor = color;
				header.style.color = getContrastColor(color);

				// Recolor the cogwheel button
				const settingsBtn = container.querySelector('.feed-settings-button');
				if (settingsBtn) settingsBtn.style.color = getContrastColor(color);
				// Unread badge
				const unreadBadge = header.querySelector('.feed-unread-badge');
				if (unreadBadge) {
					unreadBadge.style.backgroundColor = color;
					unreadBadge.style.color = getContrastColor(color);
					unreadBadge.style.borderColor = getContrastColor(color);
				}

				const iconImg = container.querySelector('.feed-settings-button img.icon');
				if (iconImg) {
					// invert if white, reset if black
					iconImg.style.filter = getContrastColor(color) === '#ffffff'
						? 'invert(1)'
						: '';
				}
			}
		});

		// Handle feed settings changes
		freshvibesView.addEventListener('change', e => {
			const feedSettingsEditor = e.target.closest('.feed-settings-editor');
			if (!feedSettingsEditor) return;

			const container = feedSettingsEditor.closest('.freshvibes-container');
			const feedId = container.dataset.feedId;

			if (e.target.classList.contains('feed-limit-select') ||
				e.target.classList.contains('feed-fontsize-select') ||
				e.target.classList.contains('feed-header-color-input')) {

				const limit = feedSettingsEditor.querySelector('.feed-limit-select').value;
				const fontSize = feedSettingsEditor.querySelector('.feed-fontsize-select').value;
				const colorInput = feedSettingsEditor.querySelector('.feed-header-color-input');
				const headerColor = state.feeds[feedId].currentHeaderColor || '';

				// Only send color if it was explicitly set
				const params = {
					feed_id: feedId,
					limit,
					font_size: fontSize
				};

				if (e.target.classList.contains('feed-header-color-input')) {
					params.header_color = e.target.value;
				} else if (headerColor) {
					params.header_color = headerColor;
				}

				api(saveFeedSettingsUrl, params).then(data => {
					if (data.status === 'success') {
						const oldLimit = state.feeds[feedId].currentLimit;
						state.feeds[feedId].currentLimit = parseInt(limit, 10);
						state.feeds[feedId].currentFontSize = fontSize;

						if (e.target.classList.contains('feed-header-color-input')) {
							state.feeds[feedId].currentHeaderColor = e.target.value;
						}

						if (oldLimit !== parseInt(limit, 10)) {
							location.reload();
						} else {
							container.className = 'freshvibes-container';
							container.classList.toggle('fontsize-xsmall', fontSize === 'xsmall');
							container.classList.toggle('fontsize-small', fontSize === 'small');
							container.classList.toggle('fontsize-large', fontSize === 'large');
							container.classList.toggle('fontsize-xlarge', fontSize === 'xlarge');
						}

						if (e.target.classList.contains('feed-header-color-input')) {
							const newColor = e.target.value;
							// reapply icon color on save
							const header = container.querySelector('.freshvibes-container-header');
							const settingsBtn = container.querySelector('.feed-settings-button');
							header.style.backgroundColor = newColor;
							header.style.color = getContrastColor(newColor);
							if (settingsBtn) settingsBtn.style.color = getContrastColor(newColor);
							const iconImg = container.querySelector('.feed-settings-button img.icon');
							if (iconImg) {
								iconImg.style.filter = getContrastColor(newColor) === '#ffffff'
									? 'invert(1)'
									: '';
							}
						}
					}
				}).catch(console.error);
			}

			freshvibesView.addEventListener('mousedown', e => {
				if (e.button === 1) { // Middle click
					const entryItem = e.target.closest('.entry-item');
					if (entryItem && !e.target.closest('a') && !e.target.closest('.entry-actions')) {
						e.preventDefault(); // Prevent autoscroll
					}
				}
			});

			// Close menus when clicking outside
			if (!e.target.closest('.tab-settings-button, .tab-settings-menu')) {
				document.querySelectorAll('.tab-settings-menu.active').forEach(m => {
					m.classList.remove('active');
					m.style.position = '';
					m.style.top = '';
					m.style.left = '';
					m.style.transform = '';
				});
			}

			if (!e.target.closest('.feed-settings-button, .feed-settings-editor')) {
				document.querySelectorAll('.feed-settings-editor.active').forEach(ed => {
					ed.classList.remove('active');
				});
			}
		});
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