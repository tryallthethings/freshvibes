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
	const { xextensionFreshvibesviewGetLayoutUrl: getLayoutUrl, xextensionFreshvibesviewSaveLayoutUrl: saveLayoutUrl, xextensionFreshvibesviewTabActionUrl: tabActionUrl, xextensionFreshvibesviewSetActiveTabUrl: setActiveTabUrl, xextensionFreshvibesviewCsrfToken: csrfToken, xextensionFreshvibesviewSaveFeedSettingsUrl: saveFeedSettingsUrl, xextensionFreshvibesviewMoveFeedUrl: moveFeedUrl, xextensionFreshvibesviewRefreshEnabled: refreshEnabled, xextensionFreshvibesviewRefreshInterval: refreshInterval, xextensionFreshvibesviewDashboardUrl: dashboardUrl, xextensionFreshvibesviewMarkReadUrl: markReadUrl, xextensionFreshvibesviewFeedUrl: feedUrl, xextensionFreshvibesviewSearchAuthorUrl: searchAuthorUrl, xextensionFreshvibesviewSearchTagUrl: searchTagUrl, xextensionFreshvibesviewDateFormat: dateFormat } = freshvibesView.dataset;
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
		state.layout.forEach(tab => tabsContainer.appendChild(createTabLink(tab)));
		const addButton = document.createElement('button');
		addButton.type = 'button';
		addButton.className = 'tab-add-button';
		addButton.textContent = '+';
		addButton.ariaLabel = tr.add_tab || 'Add new tab';
		tabsContainer.appendChild(addButton);
	}

	function renderPanels() {
		panelsContainer.innerHTML = '';
		state.layout.forEach(tab => panelsContainer.appendChild(createTabPanel(tab)));
	}

	function createTabLink(tab) {
		const link = templates.tabLink.content.cloneNode(true).firstElementChild;
		link.dataset.tabId = tab.id;
		const iconSpan = link.querySelector('.tab-icon');
		if (iconSpan) {
			iconSpan.textContent = tab.icon || '';
			iconSpan.style.color = tab.icon_color || '';
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

		return link;
	}

	function createTabPanel(tab) {
		const panel = templates.tabPanel.content.cloneNode(true).firstElementChild;
		panel.id = tab.id;
		return panel;
	}

	function setupAutoRefresh() {
		const isRefreshEnabled = refreshEnabled === 'true' || refreshEnabled === '1';
		if (!isRefreshEnabled) {
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
				favicon.style.display = '';
			} else {
				favicon.style.display = 'none';
			}
		}

		const titleElement = container.querySelector('.feed-title');
		if (titleElement) {
			titleElement.textContent = feed.name || 'Unnamed Feed';
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

					const a = document.createElement('a');
					a.href = entry.link;
					a.rel = 'noopener noreferrer';
					a.className = 'entry-title';
					a.textContent = entry.title || '(No title)';
					main.appendChild(a);

					if (entry.snippet) {
						const snippet = document.createElement('span');
						snippet.className = 'entry-snippet';
						snippet.textContent = entry.snippet;
						main.appendChild(snippet);
					}

					const date = document.createElement('span');
					date.className = 'entry-date';
					date.textContent = entry.dateShort;
					date.setAttribute('title', entry.dateFull);

					li.appendChild(main);
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
		if (modalExcerpt) modalExcerpt.textContent = entry.excerpt || entry.snippet || '';
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
			const url = `${markReadUrl}&id=${encodeURIComponent(entry.id)}&ajax=1`;
			fetch(url, {
				method: 'GET',
				credentials: 'same-origin',
				headers: {
					'X-Requested-With': 'XMLHttpRequest'
				}
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
					const url = `${markReadUrl}&id=${encodeURIComponent(entryId)}&is_read=0&ajax=1`;
					fetch(url, {
						method: 'GET',
						credentials: 'same-origin',
						headers: {
							'X-Requested-With': 'XMLHttpRequest'
						}
					}).then(() => {
						const li = document.querySelector(`[data-entry-id="${entryId}"]`);
						if (li) li.classList.remove('read');
						const feedData = state.feeds[feedId];
						const entry = feedData?.entries?.find(e => String(e.id) === entryId);
						if (entry) entry.isRead = false;
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
	}
	// --- EVENT LISTENERS ---
	function setupEventListeners() {
		freshvibesView.addEventListener('click', e => {
			if (e.target.closest('.tab-add-button')) {
				api(tabActionUrl, { operation: 'add' }).then(data => {
					if (data.status === 'success') {
						state.layout.push(data.new_tab);
						render();

					}
				}).catch(console.error);
				return;
			}

			const tabLink = e.target.closest('.freshvibes-tab');
			if (tabLink && !e.target.closest('.tab-settings-button, .tab-settings-menu')) {
				activateTab(tabLink.dataset.tabId);
				return;
			}

			if (e.target.closest('.tab-settings-button')) {
				const button = e.target.closest('.tab-settings-button');
				const menu = button.nextElementSibling;

				document.querySelectorAll('.tab-settings-menu.active').forEach(m => {
					if (m !== menu) {
						m.classList.remove('active');
						m.style.position = '';
						m.style.left = '';
						m.style.right = '';
						m.style.transform = '';
						m.style.top = '';
					}
				});

				const isActive = menu.classList.toggle('active');
				if (isActive) {
					if (window.matchMedia('(max-width: 600px)').matches) {
						const rect = button.getBoundingClientRect();
						menu.style.position = 'fixed';
						menu.style.left = '50%';
						menu.style.right = 'auto';
						menu.style.transform = 'translateX(-50%)';
						menu.style.top = rect.bottom + 'px';
					} else {
						menu.style.position = '';
						menu.style.left = '';
						menu.style.right = '';
						menu.style.transform = '';
						menu.style.top = '';
					}
				} else {
					menu.style.position = '';
					menu.style.left = '';
					menu.style.right = '';
					menu.style.transform = '';
					menu.style.top = '';
				}
				e.stopPropagation();
				return;
			}

			if (e.target.closest('.tab-action-delete')) {
				const tabId = e.target.closest('.freshvibes-tab').dataset.tabId;
				if (confirm(tr.confirm_delete_tab || 'Are you sure you want to delete this tab? Feeds on it will be moved to your first tab.')) {
					api(tabActionUrl, { operation: 'delete', tab_id: tabId }).then(data => {
						if (data.status === 'success') {
							state.layout = data.new_layout;
							state.allPlacedFeedIds = new Set(data.new_layout.flatMap(t => Object.values(t.columns).flat()).map(String));
							if (state.activeTabId === tabId) {
								state.activeTabId = state.layout[0]?.id || null;
							}
							render();
							if (state.activeTabId) {
								activateTab(state.activeTabId);
							}
						}
					}).catch(console.error);
				}
				return;
			}

			const columnsButton = e.target.closest('[data-columns]');
			if (columnsButton) {
				const numCols = columnsButton.dataset.columns;
				const tabId = columnsButton.closest('.freshvibes-tab').dataset.tabId;
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

							// Re-render source tab if we're on it
							if (state.activeTabId === sourceTabId) {
								const sourceTab = state.layout.find(t => t.id === sourceTabId);
								if (sourceTab) {
									renderTabContent(sourceTab);
								}
							}

							// Switch to target tab
							if (state.activeTabId !== targetTabId) {
								activateTab(targetTabId);
							} else {
								// If already on target tab, force re-render
								const targetTab = state.layout.find(t => t.id === targetTabId);
								if (targetTab) {
									renderTabContent(targetTab);
								}
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

				api(saveFeedSettingsUrl, { feed_id: feedId, limit, font_size: fontSize }).then(data => {
					if (data.status === 'success') {
						editor.classList.remove('active');
						const oldLimit = state.feeds[feedId].currentLimit;
						state.feeds[feedId].currentLimit = parseInt(limit, 10);
						state.feeds[feedId].currentFontSize = fontSize;

						if (oldLimit !== parseInt(limit, 10)) {
							location.reload();
						} else {
							container.className = 'freshvibes-container';
							container.classList.toggle('fontsize-xsmall', fontSize === 'xsmall');
							container.classList.toggle('fontsize-small', fontSize === 'small');
							container.classList.toggle('fontsize-large', fontSize === 'large');
							container.classList.toggle('fontsize-xlarge', fontSize === 'xlarge');
						}
					}
				}).catch(console.error);
				return;
			}

			if (e.target.closest('.feed-settings-cancel')) {
				e.target.closest('.feed-settings-editor').classList.remove('active');
				return;
			}

			const entryLink = e.target.closest('.entry-title');
			if (entryLink) {
				const li = entryLink.closest('.entry-item');
				if (li) {
					const feedId = li.dataset.feedId;
					const entryId = li.dataset.entryId;
					const feedData = state.feeds[feedId];
					const entry = feedData?.entries?.find(en => String(en.id) === entryId);
					if (entry) {
						showEntryModal(entry, li);
						e.preventDefault();
						return;
					}
				}
			}


			if (!e.target.closest('.tab-settings-menu')) {
				document.querySelectorAll('.tab-settings-menu.active').forEach(m => m.classList.remove('active'));
			}
			if (!e.target.closest('.feed-settings-editor')) {
				document.querySelectorAll('.feed-settings-editor.active').forEach(ed => ed.classList.remove('active'));
			}
		});

		tabsContainer.addEventListener('change', e => {
			if (e.target.classList.contains('tab-icon-input') || e.target.classList.contains('tab-icon-color-input')) {
				const tabEl = e.target.closest('.freshvibes-tab');
				if (!tabEl) return;
				const tabId = tabEl.dataset.tabId;
				const iconVal = tabEl.querySelector('.tab-icon-input').value.trim();
				const colorVal = tabEl.querySelector('.tab-icon-color-input').value;
				api(tabActionUrl, { operation: 'set_icon', tab_id: tabId, icon: iconVal, color: colorVal }).then(() => {
					const iconSpan = tabEl.querySelector('.tab-icon');
					if (iconSpan) {
						iconSpan.textContent = iconVal;
						iconSpan.style.color = colorVal;
					}
					const tabData = state.layout.find(t => t.id === tabId);
					if (tabData) { tabData.icon = iconVal; tabData.icon_color = colorVal; }
				}).catch(console.error);
			}
		});

		tabsContainer.addEventListener('dblclick', e => {
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