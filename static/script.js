document.addEventListener('DOMContentLoaded', () => {
	const freshvibesView = document.querySelector('.freshvibes-view');
	if (freshvibesView) {
		// Parse grouped data attributes
		try {
			const urls = JSON.parse(freshvibesView.getAttribute('data-freshvibes-urls'));
			const settings = JSON.parse(freshvibesView.getAttribute('data-freshvibes-settings'));
			const csrfToken = freshvibesView.getAttribute('data-freshvibes-csrf-token');
			initializeDashboard(freshvibesView, urls, settings, csrfToken);
		} catch (error) {
			console.error('Failed to parse dashboard data attributes:', error);
			freshvibesView.innerHTML = '<p>Error loading dashboard. Please refresh the page.</p>';
		}
	}
});

function initializeDashboard(freshvibesView, urls, settings, csrfToken) {
	// --- STATE ---
	const state = { layout: [], feeds: {}, activeTabId: null, allPlacedFeedIds: new Set() };
	let currentCsrfToken = csrfToken;
	let heightPickerHandler = null;

	// --- DOM & CONFIG ---
	const isCategoryMode = settings.mode === 'categories';
	const sortableInstances = new WeakMap();
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

	const reloadDebounce = (() => {
		let timeout;
		return (fn, delay) => {
			clearTimeout(timeout);
			timeout = setTimeout(fn, delay);
		};
	})();

	// --- RENDER FUNCTIONS ---
	function render() {
		const layoutMode = freshvibesView.getAttribute('data-layout') || 'tabs';

		if (layoutMode === 'vertical') {
			renderVerticalLayout();
		} else {
			renderTabs();
			renderPanels();
			activateTab(state.activeTabId || state.layout[0]?.id, false);
		}
	}

	function renderTabs() {
		// Store reference to subscription buttons before clearing
		const subscriptionButtons = document.querySelector('.moved-subscription-buttons');
		tabsContainer.innerHTML = '';

		state.layout.forEach(tab => {
			const link = createTabLink(tab);

			// Calculate and show unread count
			const tabUnreadCount = calculateTabUnreadCount(tab);
			const unreadBadge = link.querySelector('.tab-unread-count');
			if (unreadBadge && tabUnreadCount > 0) {
				unreadBadge.textContent = tabUnreadCount;
				unreadBadge.classList.add('has-count');
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

	function renderVerticalLayout() {
		// — store subscription buttons before we clear the layout
		const subscriptionButtons = document.querySelector('.moved-subscription-buttons');
		// Clear existing content
		tabsContainer.innerHTML = '';
		panelsContainer.innerHTML = '';

		// Hide the original containers
		tabsContainer.style.display = 'none';
		panelsContainer.style.display = 'none';

		// Create or get vertical container
		let verticalContainer = document.querySelector('.freshvibes-vertical-container');
		if (!verticalContainer) {
			verticalContainer = document.createElement('div');
			verticalContainer.className = 'freshvibes-vertical-container';
			panelsContainer.parentNode.insertBefore(verticalContainer, panelsContainer);
		}

		verticalContainer.innerHTML = '';

		// Render each tab as a section
		state.layout.forEach((tab, index) => {
			// Create section container
			const section = document.createElement('div');
			section.className = 'freshvibes-vertical-section';
			section.dataset.tabId = tab.id;

			// Create section header that will contain the tab
			const header = document.createElement('div');
			header.className = 'freshvibes-section-header';

			// Create the tab using existing function
			const tabLink = createTabLink(tab);

			// Add unread count calculation and display
			const unreadCount = calculateTabUnreadCount(tab);
			if (unreadCount > 0) {
				const badge = tabLink.querySelector('.tab-unread-count');
				if (badge) {
					badge.textContent = unreadCount;
					badge.classList.add('has-count', 'clickable');
					badge.dataset.tabId = tab.id;
				}
			}

			header.appendChild(tabLink);

			// Add to first section: bulk settings and subscription controls
			if (index === 0) {
				// Add bulk settings button
				const bulkButton = document.createElement('button');
				bulkButton.type = 'button';
				bulkButton.className = 'tab-bulk-button';
				bulkButton.id = 'bulk-settings-btn';
				bulkButton.innerHTML = '//';
				bulkButton.title = tr.bulk_settings || 'Bulk Settings';
				bulkButton.ariaLabel = tr.bulk_settings || 'Bulk Settings';
				header.appendChild(bulkButton);

				// Add subscription buttons
				if (subscriptionButtons) {
					header.appendChild(subscriptionButtons);
				}
			}

			// Add to last section: add new tab button (custom mode only)
			if (!isCategoryMode && index === state.layout.length - 1) {
				const addButton = document.createElement('button');
				addButton.type = 'button';
				addButton.className = 'tab-add-button';
				addButton.textContent = '+';
				addButton.title = tr.add_tab || 'Add new tab';
				addButton.ariaLabel = tr.add_tab || 'Add new tab';
				header.appendChild(addButton);
			}

			section.appendChild(header);

			// Create content area
			const content = document.createElement('div');
			content.className = 'freshvibes-section-content';

			const columnsContainer = document.createElement('div');
			columnsContainer.className = `freshvibes-columns columns-${tab.num_columns}`;
			content.appendChild(columnsContainer);

			// Create a panel div to maintain consistency with tab panels
			const panel = document.createElement('div');
			panel.className = 'freshvibes-panel active';
			panel.id = tab.id;
			panel.appendChild(columnsContainer);
			content.appendChild(panel);

			section.appendChild(content);
			verticalContainer.appendChild(section);

			// Render the tab content using existing function
			renderTabContent(tab);
		});

		// Initialize sortable for vertical tab headers (custom mode only)
		if (typeof Sortable !== 'undefined' && !isCategoryMode) {
			new Sortable(verticalContainer, {
				animation: 150,
				draggable: '.freshvibes-vertical-section',
				handle: '.freshvibes-tab',
				delay: 300,
				delayOnTouchOnly: true,
				onEnd: evt => {
					// Get the new order of tabs
					const newOrder = Array.from(verticalContainer.querySelectorAll('.freshvibes-vertical-section')).map(section => section.dataset.tabId);

					// Reorder the layout array
					const newLayout = [];
					newOrder.forEach(tabId => {
						const tab = state.layout.find(t => t.id === tabId);
						if (tab) newLayout.push(tab);
					});

					state.layout = newLayout;

					// Save the new layout order
					api(urls.tabAction, { operation: 'reorder', tab_ids: newOrder.join(',') })
						.then(data => {
							if (data.status !== 'success') {
								// Revert on failure
								renderVerticalLayout();
							}
						})
						.catch(error => {
							handleAPIError('Reorder vertical tabs', error);
							renderVerticalLayout();
						});
				}
			});
		}

		// Initialize sortable for all columns in vertical mode
		setTimeout(() => {
			document
				.querySelectorAll('.freshvibes-vertical-container .freshvibes-column')
				.forEach(col => initializeSortable([col]));
		}, 100);
		// Setup event handlers for vertical mode
		setupVerticalLayoutHandlers();
	}

	function updateUnreadBadge(container, count, cssClass = 'feed-unread-badge', titleText = null) {
		let badge = container.querySelector('.' + cssClass);
		if (count > 0) {
			if (!badge) {
				badge = document.createElement('span');
				badge.className = cssClass;
				badge.title = titleText || tr.mark_all_read || 'Mark all as read';
				const insertPoint = cssClass === 'feed-unread-badge'
					? container.querySelector('.feed-settings')
					: container;
				container.insertBefore(badge, insertPoint);
			}
			badge.textContent = count;
		} else if (badge) {
			badge.remove();
		}
	}

	function applyTabColors(tabEl, bgColor, fontColor = '') {
		if (bgColor) {
			tabEl.style.setProperty('--tab-bg-color', bgColor);
			tabEl.style.setProperty('--tab-font-color', fontColor || getContrastColor(bgColor));
			tabEl.classList.add('has-custom-color');
		} else {
			tabEl.style.removeProperty('--tab-bg-color');
			tabEl.style.removeProperty('--tab-font-color');
			tabEl.classList.remove('has-custom-color');
		}
	}

	function resetColorInput(colorInput, defaultColor = '#f0f0f0') {
		// Validate hex color format
		const isValidHex = /^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/.test(defaultColor);
		const safeColor = isValidHex ? defaultColor : '#f0f0f0';

		colorInput.value = safeColor;
		if (colorInput.dataset) {
			colorInput.dataset.reset = '1';
		}
		return safeColor;
	}

	function getColorFromComputedStyle(element, className) {
		const temp = document.createElement('div');
		temp.className = className;
		document.body.appendChild(temp);
		const style = window.getComputedStyle(temp).backgroundColor;
		document.body.removeChild(temp);

		const rgb = style.match(/\d+/g);
		if (rgb) {
			return '#' + rgb.map(x => parseInt(x).toString(16).padStart(2, '0')).join('');
		}
		return '#f0f0f0';
	}

	function setupHeightPickerHandler(activePickers) {
		return function (e) {
			const heightInput = e.target.closest('.feed-maxheight-input, #bulk-feed-maxheight');
			const pickerButton = e.target.closest('.fv-height-picker button');
			const container = e.target.closest('.freshvibes-container, #bulk-settings-modal');

			if (pickerButton) {
				e.stopPropagation();
				const picker = pickerButton.closest('.fv-height-picker');
				const input = picker.previousElementSibling;
				if (input) {
					input.value = pickerButton.dataset.value;
					input.dispatchEvent(new Event('change', { bubbles: true }));
				}
				picker.classList.remove('active');
				activePickers.delete(container);
				return;
			}

			if (heightInput) {
				e.stopPropagation();
				const picker = heightInput.nextElementSibling;
				// Close other pickers
				activePickers.forEach((p, c) => {
					if (c !== container) {
						p.classList.remove('active');
						activePickers.delete(c);
					}
				});

				picker.classList.toggle('active');
				if (picker.classList.contains('active')) {
					activePickers.set(container, picker);
				} else {
					activePickers.delete(container);
				}
				return;
			}

			// Close all pickers when clicking outside
			activePickers.forEach(p => p.classList.remove('active'));
			activePickers.clear();
		};
	}

	function updateColumnButtonState(container, activeColumns) {
		container.querySelectorAll('.columns-selector button').forEach(btn => {
			btn.classList.toggle('active', parseInt(btn.dataset.columns) === activeColumns);
		});
	}

	function setupVerticalLayoutHandlers() {
		// Mark all as read for sections
		document.querySelectorAll('.section-unread-count.clickable').forEach(badge => {
			badge.addEventListener('click', e => {
				e.stopPropagation();
				const section = badge.closest('.freshvibes-vertical-section');
				const tabId = section.dataset.tabId;
				const tabData = state.layout.find(t => t.id === tabId);
				const shouldConfirm = settings.confirmMarkRead === '1';

				if (tabData && parseInt(badge.textContent) > 0) {
					const performMarkRead = () => {
						api(urls.markTabRead, { tab_id: tabId }).then(data => {
							if (data.status === 'success') {
								badge.textContent = '0';
								badge.classList.remove('has-count');
								tabData.unread_count = 0;

								section.querySelectorAll('.freshvibes-container').forEach(container => {
									const unreadBadge = container.querySelector('.feed-unread-badge');
									if (unreadBadge) unreadBadge.remove();
									container.querySelectorAll('.entry-item:not(.read)').forEach(li => li.classList.add('read'));
								});

								Object.values(tabData.columns || {}).flat().forEach(feedId => {
									const feed = state.feeds[feedId];
									if (feed) feed.nbUnread = 0;
								});
							}
						}).catch(error => handleAPIError('Mark section read', error));
					};

					if (shouldConfirm) {
						if (confirm(tr.confirm_mark_tab_read || `Mark all entries in "${tabData.name}" as read?`)) {
							performMarkRead();
						}
					} else {
						performMarkRead();
					}
				}
			});

		});

		// Enable double-click rename for vertical tabs
		document.querySelectorAll('.freshvibes-vertical-section .tab-name').forEach(nameEl => {
			nameEl.addEventListener('dblclick', handleTabRename);
		});
	}

	function handleTabRename(e) {
		if (isCategoryMode) return;
		const tabNameSpan = e.target.closest('.tab-name');
		if (!tabNameSpan) {
			return;
		}
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
			if (input.parentNode) input.replaceWith(tabNameSpan);

			if (newName && newName !== oldName) {
				tabNameSpan.textContent = newName;
				api(urls.tabAction, { operation: 'rename', tab_id: tabId, value: newName })
					.then(data => {
						if (data.status === 'success') {
							const tabInState = state.layout.find(t => t.id === tabId);
							if (tabInState) {
								tabInState.name = newName;
								updateSlugURL(state, tabInState);
							}
							document.querySelectorAll(`.feed-move-to-list button[data-target-tab-id="${tabId}"]`)
								.forEach(btn => {
									btn.textContent = newName;
									btn.setAttribute('aria-label', `Move feed to tab: ${newName}`);
								});
						} else {
							tabNameSpan.textContent = oldName;
						}
					})
					.catch(() => tabNameSpan.textContent = oldName);
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
	}


	function renderPanels() {
		panelsContainer.innerHTML = '';
		state.layout.forEach(tab => panelsContainer.appendChild(createTabPanel(tab)));
	}

	function calculateTabUnreadCount(tab) {
		let count = 0;
		if (tab.columns) {
			Object.values(tab.columns).forEach(feedIds => {
				feedIds.forEach(feedId => {
					const feed = state.feeds[feedId];
					if (feed?.nbUnread) {
						count += feed.nbUnread;
					}
				});
			});
		}
		return count;
	}

	function saveFeedSettings(feedId, settingsData) {
		const payload = {
			feed_id: feedId,
			limit: settingsData.limit,
			font_size: settingsData.fontSize,
			header_color: settingsData.headerColor,
			max_height: settingsData.maxHeight,
			display_mode: settingsData.displayMode,
		};

		Object.keys(payload).forEach(key => {
			if (payload[key] === undefined) {
				delete payload[key];
			}
		});

		return api(urls.saveFeedSettings, payload);
	}

	function handleAPIError(context, error) {
		console.error(`FreshVibesView: ${context}`, error);
		if (error.requiresAuth) {
			showAuthNotification();
		}
	}

	function createTabLink(tab) {
		const link = templates.tabLink.content.cloneNode(true).firstElementChild;
		link.dataset.tabId = tab.id;

		// Apply saved colors via CSS variables
		if (tab.bg_color) {
			applyTabColors(link, tab.bg_color, tab.font_color);
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
		updateColumnButtonState(link, tab.num_columns);

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

				const defaultColor = getColorFromComputedStyle(document.body, 'freshvibes-tab');
				resetColorInput(bgColorInput, defaultColor);
			}
		}

		// Show unread count
		const unreadCount = link.querySelector('.tab-unread-count');
		if (unreadCount && tab.unread_count > 0) {
			unreadCount.textContent = tab.unread_count;
			unreadCount.classList.add('has-count');
			unreadCount.title = tr.confirm_mark_tab_read || 'Mark all entries in this tab as read?';
		}

		// Add link to native FreshRSS category settings inside the menu
		const settingsMenu = link.querySelector('.tab-settings-menu');
		if (isCategoryMode && urls.categorySettings && tab.id.startsWith('cat-') && settingsMenu) {
			const categoryId = tab.id.substring(4);
			const settingsLink = document.createElement('a');
			settingsLink.href = urls.categorySettings + categoryId;
			settingsLink.className = 'fv-native-settings-link';
			settingsLink.textContent = tr.edit_category_settings || 'Edit category in FreshRSS';
			settingsLink.target = '_blank';
			settingsLink.rel = 'noopener noreferrer';

			const linkContainer = document.createElement('div');
			linkContainer.className = 'fv-native-link-section';
			linkContainer.appendChild(settingsLink);

			// Append before the delete button for consistent placement
			const deleteButton = settingsMenu.querySelector('.tab-action-delete');
			if (deleteButton) {
				settingsMenu.insertBefore(linkContainer, deleteButton);
			} else {
				settingsMenu.appendChild(linkContainer);
			}
		}

		return link;
	}

	function createTabPanel(tab) {
		const panel = templates.tabPanel.content.cloneNode(true).firstElementChild;
		panel.id = tab.id;
		return panel;
	}

	function setupAutoRefresh() {
		// Read settings
		const refreshEnabled = settings.refreshEnabled === 'true' || settings.refreshEnabled === '1' || settings.refreshEnabled === 1;
		const intervalMinutes = parseInt(settings.refreshInterval, 10) || 15;
		const refreshMs = intervalMinutes * 60 * 1000;

		// Validate settings
		if (!refreshEnabled || !urls.refreshFeeds || refreshMs <= 0) {
			return;
		}

		const refreshLoop = () => {
			const isInteracting = document.querySelector('.tab-settings-menu.active, .feed-settings-editor.active, .fv-modal.active') ||
				(document.activeElement && ['INPUT', 'TEXTAREA', 'BUTTON', 'A'].includes(document.activeElement.tagName));

			if (isInteracting) {
				setTimeout(refreshLoop, refreshMs);
				return;
			}

			let isRefreshing = false;
			// Instead of calling refreshfeeds, reload the page via AJAX
			fetch(window.location.href, {
				headers: { 'X-Requested-With': 'XMLHttpRequest' }
			})
				.then(res => res.text())
				.then(html => {
					// Skip update if user is interacting
					const isInteracting = document.querySelector('.tab-settings-menu.active, .feed-settings-editor.active, .fv-modal.active');
					if (isInteracting || isRefreshing) return;

					isRefreshing = true;
					// Extract feeds data from the response
					const parser = new DOMParser();
					const doc = parser.parseFromString(html, 'text/html');
					const feedsScript = doc.getElementById('feeds-data-script');

					if (feedsScript) {
						const newFeedsData = JSON.parse(feedsScript.textContent);
						state.feeds = newFeedsData;
						renderTabs();
						isRefreshing = false;
						const activeTab = state.layout.find(t => t.id === state.activeTabId);
						if (activeTab) {
							render();
						}
					}
				})
				.catch(error => {
					console.log('Refresh error:', error.message);
				})
				.finally(() => {
					const nextTime = new Date(Date.now() + refreshMs);
					console.log('Next refresh scheduled for:', nextTime.toLocaleTimeString());
					setTimeout(refreshLoop, refreshMs);
				});
		};

		// Start the first refresh cycle.
		setTimeout(refreshLoop, refreshMs);
	}

	function renderTabContent(tab) {
		const panel = document.getElementById(tab.id);
		if (!panel) return;

		const columnsContainer = panel.querySelector('.freshvibes-columns');

		// Clean up duplicates within tab columns before rendering
		if (tab.columns && typeof tab.columns === 'object') {
			const seenInTab = new Set();
			Object.keys(tab.columns).forEach(colId => {
				if (Array.isArray(tab.columns[colId])) {
					tab.columns[colId] = tab.columns[colId].filter(feedId => {
						const feedIdStr = String(feedId);
						if (seenInTab.has(feedIdStr)) {
							return false;
						}
						seenInTab.add(feedIdStr);
						return true;
					});
				}
			});
		}

		// Destroy any existing Sortable instances before clearing the DOM
		if (columnsContainer) {
			columnsContainer.querySelectorAll('.freshvibes-column').forEach(column => {
				const sortable = sortableInstances.get(column);
				if (sortable) {
					sortable.destroy();
					sortableInstances.delete(column);
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

		const renderedFeeds = new Set();

		// Render feeds that are explicitly placed in the current tab's layout.
		if (tab.columns && typeof tab.columns === 'object') {
			Object.entries(tab.columns).forEach(([colId, feedIds]) => {
				const colIndex = parseInt(colId.replace('col', ''), 10) - 1;
				if (columns[colIndex] && Array.isArray(feedIds)) {
					feedIds.forEach(feedId => {
						const feedIdStr = String(feedId);
						const feedData = state.feeds[feedIdStr] || state.feeds[feedId];

						if (feedData && !renderedFeeds.has(feedIdStr)) {
							columns[colIndex].appendChild(createFeedContainer(feedData, tab.id));
							renderedFeeds.add(feedIdStr);
						}
					});
				}
			});
		}

		//  On the very first tab, also render any feeds that are not placed in *any* tab's layout.
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
		const handle = document.createElement('div');
		handle.className = 'fv-resize-handle';
		container.appendChild(handle);

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
			titleLink.textContent = feed.name || 'Unnamed Feed';
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

			updateUnreadBadge(headerElement, feed.nbUnread);
		}

		const contentDiv = container.querySelector('.freshvibes-container-content');
		if (contentDiv) {
			contentDiv.innerHTML = '';
			// Apply max-height
			if (feed.currentMaxHeight && !['unlimited', 'fit'].includes(feed.currentMaxHeight)) {
				contentDiv.style.height = feed.currentMaxHeight + 'px';
			} else {
				contentDiv.style.height = '';
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
					// Use translations from the tr object if available
					if (tr[`font_size_${val}`]) {
						label = tr[`font_size_${val}`];
					} else {
						if (val === 'xsmall') label = 'Extra Small';
						if (val === 'xlarge') label = 'Extra Large';
					}
					const opt = new Option(label, val, val === feed.currentFontSize, val === feed.currentFontSize);
					fontSelect.add(opt);
				});
			}

			const displayModeSelect = editor.querySelector('.feed-display-mode-select');
			if (displayModeSelect) {
				['tiny', 'compact', 'detailed'].forEach(mode => {
					const label = tr[`display_mode_${mode}`] || (mode.charAt(0).toUpperCase() + mode.slice(1));
					const opt = new Option(label, mode, mode === feed.currentDisplayMode, mode === feed.currentDisplayMode);
					displayModeSelect.add(opt);
				});
			}

			const headerColorInput = editor.querySelector('.feed-header-color-input');
			if (headerColorInput) {
				if (feed.currentHeaderColor) {
					headerColorInput.value = feed.currentHeaderColor;
				} else {
					// Set a light gray default that matches the visual appearance
					headerColorInput.value = '#f0f0f0';
				}
			}

			const maxHeightSelect = editor.querySelector('.feed-maxheight-select');
			if (maxHeightSelect) {
				const parentRow = maxHeightSelect.parentElement;
				const label = parentRow.querySelector('label');
				parentRow.innerHTML = ''; // Clear the row
				if (label) parentRow.appendChild(label); // Re-add the label

				const wrapper = document.createElement('div');
				wrapper.className = 'height-picker-wrapper';

				const input = document.createElement('input');
				input.type = 'text';
				input.className = 'feed-maxheight-input';
				input.value = feed.currentMaxHeight;
				input.placeholder = feed.currentMaxHeight;

				const picker = document.createElement('div');
				picker.className = 'fv-height-picker';

				['300', '400', '500', '600', '700', '800', 'unlimited', 'fit'].forEach(val => {
					const btn = document.createElement('button');
					btn.type = 'button';
					btn.dataset.value = val;
					let text = val;
					if (val === 'unlimited') text = tr.unlimited || 'Unlimited';
					if (val === 'fit') text = tr.fit_to_content || 'Fit to content';
					if (!isNaN(parseInt(val))) text += 'px';
					btn.textContent = text;
					picker.appendChild(btn);
				});

				wrapper.append(input, picker);
				parentRow.appendChild(wrapper);
			}

			// Add link to native FreshRSS feed settings
			if (urls.feedSettings) {
				const settingsLink = document.createElement('a');
				settingsLink.href = urls.feedSettings + feed.id;
				settingsLink.className = 'fv-native-settings-link';
				settingsLink.textContent = tr.edit_feed_settings || 'Edit feed in FreshRSS';
				settingsLink.target = '_blank';
				settingsLink.rel = 'noopener noreferrer';

				const linkContainer = document.createElement('div');
				linkContainer.className = 'fv-native-link-section'; // A new container for styling
				linkContainer.appendChild(settingsLink);

				const moveToSection = editor.querySelector('.feed-move-to');
				if (moveToSection) {
					// Insert before the 'move to' section
					editor.insertBefore(linkContainer, moveToSection);
				} else {
					// If no 'move to' section, append before the save/cancel buttons
					const buttonsRow = editor.querySelector('.setting-row-buttons');
					if (buttonsRow) {
						editor.insertBefore(linkContainer, buttonsRow);
					} else {
						editor.appendChild(linkContainer);
					}
				}
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
		makeResizable(container);
		return container;
	}

	function showEntryModal(entry, li) {
		if (!entryModal) return;

		document.body.classList.add('modal-open');

		const feedData = state.feeds[entry.feedId];

		if (modalTitle) modalTitle.textContent = entry.title || '';

		if (modalFeed && feedData && modalFeedIcon && modalFeedName) {
			modalFeed.href = urls.feed.replace('f_', 'f_' + feedData.id);
			modalFeedIcon.classList.toggle('hidden', !feedData.favicon);
			if (feedData.favicon) modalFeedIcon.src = feedData.favicon;
			modalFeedName.textContent = feedData.name || '';
		}

		if (modalAuthorWrapper && modalAuthor && modalAuthorPrefix) {
			const cleanAuthor = entry.author ? entry.author.replace(/^[;:\s]+/, '').trim() : '';
			modalAuthorWrapper.classList.toggle('hidden', !cleanAuthor);
			if (cleanAuthor) {
				modalAuthorPrefix.textContent = tr.by_author || 'By: ';
				modalAuthor.textContent = cleanAuthor;
				modalAuthor.href = urls.searchAuthor + '&search=' + encodeURIComponent('author:"' + cleanAuthor + '"');
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
					a.href = urls.searchTag + '&search=' + encodeURIComponent('#' + tag);
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

		if (!entry.isRead && urls.markRead) {
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

			api(urls.markRead, { id: entry.id, ajax: 1, is_read: 1 }).catch(error => handleAPIError('Mark entry read', error));
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
				if (urls.markRead && entryId && feedId) {
					api(urls.markRead, {
						id: entryId,
						is_read: 0,
						ajax: 1
					}).then(data => {
						if (data.status === 'error') return;
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
									const header = container.querySelector('.freshvibes-container-header');
									updateUnreadBadge(header, feedData.nbUnread);
								}
								updateTabBadge(feedId);
							}
						}
						entryModal.classList.remove('active');
					}).catch(error => handleAPIError('Mark entry unread', error));
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

		const displayDate = settings.dateMode === 'relative' ? entry.dateRelative : entry.dateShort;

		const favoriteIndicator = document.createElement('span');
		favoriteIndicator.className = 'entry-favorite-indicator';
		if (entry.isFavorite) {
			favoriteIndicator.classList.add('is-favorite');
		}

		favoriteIndicator.innerHTML = tr.icon_starred || '⭐';

		const titleSpan = document.createElement('span');
		titleSpan.className = 'entry-title';
		titleSpan.textContent = entry.title || '(No title)';

		const dateSpan = document.createElement('span');
		dateSpan.className = 'entry-date';
		dateSpan.title = entry.dateFull;
		dateSpan.textContent = displayDate;

		const entryLink = document.createElement('a');
		entryLink.className = 'entry-link';
		entryLink.href = entry.link;
		entryLink.target = '_blank';
		entryLink.rel = 'noopener noreferrer';
		entryLink.dataset.entryId = entry.id;
		entryLink.dataset.feedId = feed.id;

		if (feed.currentDisplayMode === 'tiny') {
			const mainDiv = document.createElement('div');
			mainDiv.className = 'entry-main';
			mainDiv.append(favoriteIndicator, titleSpan);

			if (snippetToUse) {
				const snippetSpan = document.createElement('span');
				snippetSpan.className = 'entry-snippet';
				snippetSpan.textContent = snippetToUse;
				mainDiv.appendChild(snippetSpan);
			}
			entryLink.append(mainDiv, dateSpan);
			li.appendChild(entryLink);
		} else {
			// For Compact and Detailed views
			const wrapper = document.createElement('div');
			wrapper.className = 'entry-wrapper';

			const headerDiv = document.createElement('div');
			headerDiv.className = 'entry-header';

			entryLink.append(favoriteIndicator, titleSpan);
			headerDiv.append(entryLink, dateSpan);
			wrapper.appendChild(headerDiv);

			if (snippetToUse) {
				const excerptDiv = document.createElement('div');
				excerptDiv.className = 'entry-excerpt';

				// The 'detailed' snippet now contains HTML intended for the modal.
				// We must convert it to plain text here for the list view.
				const tempDiv = document.createElement('div');
				tempDiv.innerHTML = snippetToUse;
				excerptDiv.textContent = tempDiv.textContent || tempDiv.innerText || '';

				wrapper.appendChild(excerptDiv);
			}
			li.appendChild(wrapper);
		}


		// Add action buttons using the template
		const actionsTemplate = document.getElementById('template-entry-actions');
		if (actionsTemplate) {
			const actions = actionsTemplate.content.cloneNode(true);
			const btn = actions.querySelector('.entry-action-btn[data-action="toggle"]');
			if (btn) {
				btn.classList.toggle('is-read', entry.isRead);
				btn.title = entry.isRead ? (tr.mark_unread || 'Mark as unread') : (tr.mark_read || 'Mark as read');
			}
			const favBtn = actions.querySelector('.entry-fav-btn');
			if (favBtn) {
				favBtn.classList.toggle('is-favorite', entry.isFavorite);
				favBtn.title = tr.mark_favorite || 'Toggle favourite';
			}
			li.appendChild(actions);
		}

		return li;
	}

	// --- ACTIONS ---
	function refreshCsrfToken() {
		// Fetch the current page to extract a fresh CSRF token
		return fetch(window.location.href, {
			method: 'GET',
			headers: { 'X-Requested-With': 'XMLHttpRequest' },
			credentials: 'same-origin'
		})
			.then(res => res.text())
			.then(html => {
				// Parse the HTML to find the CSRF token
				const parser = new DOMParser();
				const doc = parser.parseFromString(html, 'text/html');
				const freshvibesView = doc.querySelector('.freshvibes-view');
				if (freshvibesView) {
					const newToken = freshvibesView.getAttribute('data-freshvibes-csrf-token');

					if (newToken && newToken !== currentCsrfToken) {
						currentCsrfToken = newToken;
						return true;
					}
				}
				return false;
			})
			.catch(err => {
				return false;
			});
	}

	function api(url, body, retryCount = 0) {
		return fetch(url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
			body: new URLSearchParams({ ...body, '_csrf': currentCsrfToken }),
		}).then(res => {
			if (res.status === 403 && retryCount === 0) {
				// Try to refresh CSRF token and retry once
				return refreshCsrfToken().then(success => {
					if (success) {
						return api(url, body, 1);
					} else {
						showAuthNotification();
						return { status: 'error', requiresAuth: true };
					}
				});
			}

			if (res.status === 403) {
				showAuthNotification();
				return { status: 'error', requiresAuth: true };
			}

			const contentType = res.headers.get('content-type');
			if (!contentType || !contentType.includes('application/json')) {
				showAuthNotification();
				return { status: 'error', requiresAuth: true };
			}

			return res.json();
		}).catch(error => {
			// Network error or CORS issue
			console.error('Fetch error:', error);
			showAuthNotification();
			return { status: 'error', requiresAuth: true };
		});
	}

	function showAuthNotification() {
		if (document.querySelector('.freshvibes-auth-notice')) return;

		const notificationArea = document.querySelector('#notification');
		if (!notificationArea) return;

		// Remove 'closed' class and make visible
		notificationArea.classList.remove('closed');
		notificationArea.style.display = 'block';

		// Find the message span
		const msgSpan = notificationArea.querySelector('.msg');
		if (!msgSpan) return;

		// Add our message to the span
		msgSpan.className = 'msg bad freshvibes-auth-notice';
		msgSpan.innerHTML = `${tr.login_required || 'You need to be logged in to make changes.'} <a href="?c=auth&a=login">${tr.login || 'Login'}</a>`;

		// Auto-hide after 5 seconds
		setTimeout(() => {
			notificationArea.classList.add('closed');
			msgSpan.innerHTML = '';
			msgSpan.className = 'msg';
		}, 5000);
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
			api(urls.setActiveTab, { tab_id: tabId }).catch(error => handleAPIError('Set active tab', error));
		}
	}

	function initializeSortable(columns) {
		if (typeof Sortable === 'undefined') return;

		columns.forEach(column => {
			if (column.sortable) return;

			const sortable = new Sortable(column, {
				group: 'freshvibes-feeds',
				animation: 0,
				handle: '.freshvibes-container-header',
				delay: 300,
				delayOnTouchOnly: true,
				onMove: evt => {
					if (isCategoryMode) {
						const sourcePanel = evt.from.closest('.freshvibes-panel');
						const targetPanel = evt.to.closest('.freshvibes-panel');
						if (sourcePanel && targetPanel && sourcePanel.id !== targetPanel.id) {
							return false; // Prevent the move
						}
					}
				},
				onEnd: evt => {
					const sourcePanel = evt.from.closest('.freshvibes-panel');
					const targetPanel = evt.to.closest('.freshvibes-panel');
					if (!sourcePanel || !targetPanel) return;

					const sourceTabId = sourcePanel.id;
					const targetTabId = targetPanel.id;
					const movedFeedId = evt.item.dataset.feedId;

					if (sourceTabId === targetTabId) {
						const layoutData = {};
						targetPanel.querySelectorAll('.freshvibes-column').forEach(col => {
							const colId = col.dataset.columnId;
							layoutData[colId] = Array.from(col.querySelectorAll('.freshvibes-container')).map(c => c.dataset.feedId);
						});

						const tab = state.layout.find(t => t.id === targetTabId);
						if (tab) {
							tab.columns = layoutData;
							api(urls.saveLayout, { layout: JSON.stringify(layoutData), tab_id: targetTabId })
								.catch(error => handleAPIError('Save layout', error));
						}
					} else {
						// --- CROSS TAB DRAG ---
						api(urls.moveFeed, { feed_id: movedFeedId, source_tab_id: sourceTabId, target_tab_id: targetTabId })
							.then(data => {
								if (data.status === 'success' && data.new_layout) {
									state.layout = assignUniqueSlugs(data.new_layout);
									state.allPlacedFeedIds = new Set(data.new_layout.flatMap(t =>
										Object.values(t.columns || {}).flat()
									).map(String));

									// Re-render everything to be safe
									render();
								} else {
									// On failure, reload to prevent inconsistent state
									alert(tr.error_moving_feed || 'Error moving feed. The page will now reload to ensure consistency.');
									location.reload();
								}
							})
							.catch(error => {
								handleAPIError('Save cross-tab drag', error);
								alert(tr.error_moving_feed || 'Error moving feed. The page will now reload to ensure consistency.');
								location.reload();
							});
					}
				}
			});
			sortableInstances.set(column, sortable);
		});

		if (typeof Sortable !== 'undefined' && tabsContainer && !tabsContainer.sortable && !isCategoryMode) {
			tabsContainer.sortable = new Sortable(tabsContainer, {
				animation: 150,
				draggable: '.freshvibes-tab',
				filter: '.tab-add-button',
				delay: 300,
				delayOnTouchOnly: true,
				onEnd: evt => {
					const newOrder = Array.from(tabsContainer.querySelectorAll('.freshvibes-tab')).map(tab => tab.dataset.tabId);

					const newLayout = [];
					newOrder.forEach(tabId => {
						const tab = state.layout.find(t => t.id === tabId);
						if (tab) newLayout.push(tab);
					});

					state.layout = newLayout;

					api(urls.tabAction, { operation: 'reorder', tab_ids: newOrder.join(',') })
						.then(data => {
							if (data.status !== 'success') {
								render();
							}
						})
						.catch(error => {
							handleAPIError('Reorder tabs', error);
							render();
						});
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
					// Ensure consistent comparison
					const normalizedFeedIds = feedIds.map(id => String(id));
					const normalizedFeedId = String(feedId);
					if (normalizedFeedIds.includes(normalizedFeedId)) {
						containingTabId = tab.id;
						break;
					}
				}
			}
			if (containingTabId) break;
		}

		if (!containingTabId) return;

		// Recalculate tab unread count
		const tab = state.layout.find(t => t.id === containingTabId);
		const tabUnreadCount = calculateTabUnreadCount(tab);

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

	function saveTabColors(tabId, bgColor, fontColor = '') {
		const finalFontColor = fontColor || (bgColor ? getContrastColor(bgColor) : '');

		return api(urls.tabAction, {
			operation: 'set_colors',
			tab_id: tabId,
			bg_color: bgColor,
			font_color: finalFontColor
		}).then(data => {
			if (data.status === 'success') {
				const tabData = state.layout.find(t => t.id === tabId);
				if (tabData) {
					tabData.bg_color = bgColor;
					tabData.font_color = finalFontColor;
				}
			}
			return data;
		});
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
			const base = slugify(tab.name) || 'tab';
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

	function makeResizable(container) {
		const handle = container.querySelector('.fv-resize-handle');
		const content = container.querySelector('.freshvibes-container-content');
		const editorInput = container.querySelector('.feed-maxheight-input');

		handle.addEventListener('mousedown', function (e) {
			e.preventDefault();
			const startY = e.clientY;
			const startHeight = content.offsetHeight;

			function doDrag(e) {
				const newHeight = startHeight + e.clientY - startY;
				if (newHeight > 50) { // Minimum height to prevent too small containers
					content.style.height = newHeight + 'px';
				}
			}

			function stopDrag() {
				document.removeEventListener('mousemove', doDrag);
				document.removeEventListener('mouseup', stopDrag);

				if (!editorInput) return;

				const finalHeight = content.offsetHeight;
				editorInput.value = finalHeight;

				// This saves the resized value directly to ensure it always works.
				const feedId = container.dataset.feedId;
				const editor = container.querySelector('.feed-settings-editor');
				if (!feedId || !editor) return;

				saveFeedSettings(feedId, {
					limit: editor.querySelector('.feed-limit-select').value,
					fontSize: editor.querySelector('.feed-fontsize-select').value,
					headerColor: editor.querySelector('.feed-header-color-input').value,
					maxHeight: String(finalHeight),
					displayMode: editor.querySelector('.feed-display-mode-select').value,
				}).then(data => {
					if (data.status === 'success') {
						const feed = state.feeds[feedId];
						if (feed) {
							feed.currentMaxHeight = String(finalHeight);
						}
					}
				}).catch(error => handleAPIError('Save resized height', error));
			}

			document.addEventListener('mousemove', doDrag);
			document.addEventListener('mouseup', stopDrag);
		});
	}

	// --- EVENT LISTENERS ---
	function setupEventListeners() {
		freshvibesView.addEventListener('click', e => {
			// Handle opening/closing the tab settings menu
			const settingsButton = e.target.closest('.tab-settings-button');
			const activeMenu = document.querySelector('.tab-settings-menu.active');

			const closeAndResetMenu = (menuToClose) => {
				if (!menuToClose) return;
				const parentTab = menuToClose.closest('.freshvibes-tab');
				menuToClose.classList.remove('active');
				parentTab?.classList.remove('menu-is-open');

				menuToClose.style.left = '';
				menuToClose.style.right = '';
				menuToClose.style.visibility = '';
			};

			// If a menu is active and the click is outside it and not on any settings button, close it.
			if (activeMenu && !activeMenu.contains(e.target) && !settingsButton) {
				closeAndResetMenu(activeMenu);
			}

			if (settingsButton) {
				e.stopPropagation();
				const menu = settingsButton.nextElementSibling;
				const tab = settingsButton.closest('.freshvibes-tab');
				const wasActive = menu.classList.contains('active');

				// Always close any currently active menu first.
				closeAndResetMenu(activeMenu);

				if (!wasActive) {
					tab.classList.add('menu-is-open');

					// Temporarily make menu visible for measurement without a visual flash
					menu.style.visibility = 'hidden';
					menu.classList.add('active');

					const menuRect = menu.getBoundingClientRect();
					const tabRect = tab.getBoundingClientRect();

					// Revert temporary style before applying final position
					// Correct position only if it overflows the viewport
					if (menuRect.right > window.innerWidth - 10) {
						const overflow = menuRect.right - (window.innerWidth - 10);
						// Default CSS is `right: -10px`
						menu.style.right = `${-10 + overflow}px`;
					}

					if (menuRect.left < 10) {
						// Switch to left-based positioning when it hits the left edge
						menu.style.right = 'auto';
						menu.style.left = `${10 - tabRect.left}px`;
					}

					// Now that position is finalized, make it visible
					menu.style.visibility = '';
				}
				return;
			}

			if (e.target.closest('.tab-settings-menu')) {
				// Handle column buttons
				const columnsButton = e.target.closest('[data-columns]');
				if (columnsButton) {
					e.stopPropagation();
					const numCols = columnsButton.dataset.columns;
					const tabId = columnsButton.closest('.freshvibes-tab').dataset.tabId;

					// Update active state immediately
					updateColumnButtonState(columnsButton.parentElement, parseInt(numCols));

					api(urls.tabAction, { operation: 'set_columns', tab_id: tabId, value: numCols }).then(data => {
						if (data.status === 'success') {
							state.layout = data.new_layout;
							state.allPlacedFeedIds = new Set(data.new_layout.flatMap(t => Object.values(t.columns).flat()).map(String));
							const tabData = state.layout.find(t => t.id === tabId);
							renderTabContent(tabData);
						}
					}).catch(error => handleAPIError('Set columns', error));
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
						api(urls.tabAction, { operation: 'delete', tab_id: tabId })
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
							.catch(error => handleAPIError('Delete tab', error));
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

						applyTabColors(tabEl, '', '');

						saveTabColors(tabId, '', '').then(data => {
							if (data.status === 'success') {

								// Reset color‐picker to default computed style
								const tempTab = document.createElement('div');
								tempTab.className = 'freshvibes-tab';
								document.body.appendChild(tempTab);
								const defaultBg = window.getComputedStyle(tempTab).backgroundColor;
								document.body.removeChild(tempTab);

								const rgb = defaultBg.match(/\d+/g);
								if (rgb) {
									const hex = '#' + rgb.map(x => parseInt(x).toString(16).padStart(2, '0')).join('');
									resetColorInput(colorInput, hex);
								}
							}
						})
							.catch(error => handleAPIError('Reset tab color', error));
					}
					return;
				}

				// Don't close menu for other clicks inside
				e.stopPropagation();
				return;
			}

			if (e.target.closest('.tab-add-button')) {
				if (isCategoryMode) return;
				api(urls.tabAction, { operation: 'add' }).then(data => {
					if (data.status === 'success') {
						state.layout.push(data.new_tab);
						updateSlugURL(state, data.new_tab);
						render();
						activateTab(data.new_tab.id, false);
					}
				}).catch(error => handleAPIError('Add tab', error));
				return;
			}

			const tabLink = e.target.closest('.freshvibes-tab');
			if (tabLink && !e.target.closest('.tab-settings-button, .tab-settings-menu, .tab-unread-count')) {
				activateTab(tabLink.dataset.tabId);
				return;
			}

			const columnsButton = e.target.closest('[data-columns]');
			if (columnsButton) {
				const numCols = columnsButton.dataset.columns;
				const tabId = columnsButton.closest('.freshvibes-tab').dataset.tabId;

				// Update active state immediately
				updateColumnButtonState(columnsButton.parentElement, parseInt(numCols));

				api(urls.tabAction, { operation: 'set_columns', tab_id: tabId, value: numCols }).then(data => {
					if (data.status === 'success') {
						state.layout = assignUniqueSlugs(data.new_layout);
						state.allPlacedFeedIds = new Set(data.new_layout.flatMap(t => Object.values(t.columns).flat()).map(String));
						const tabData = state.layout.find(t => t.id === tabId);
						renderTabContent(tabData);
					}
				}).catch(error => handleAPIError('Set columns', error));
				return;
			}

			if (e.target.closest('.feed-settings-button')) {
				const button = e.target.closest('.feed-settings-button');
				const editor = button.nextElementSibling;

				editor.classList.toggle('active');

				// Position editor to stay within viewport
				if (editor.classList.contains('active')) {
					// Use fixed positioning to escape stacking context
					const buttonRect = button.getBoundingClientRect();

					editor.style.position = 'fixed';
					editor.style.top = `${buttonRect.bottom + 5}px`;
					editor.style.right = `${window.innerWidth - buttonRect.right}px`;
					editor.style.left = 'auto';

					// Check if it goes off screen and adjust
					const editorRect = editor.getBoundingClientRect();
					const viewportWidth = window.innerWidth;

					if (editorRect.right > viewportWidth - 10) {
						editor.style.right = '10px';
					}

					if (editorRect.left < 10) {
						editor.style.left = '10px';
						editor.style.right = 'auto';
					}

					// Check vertical position
					if (editorRect.bottom > window.innerHeight - 10) {
						// Position above the button instead
						editor.style.top = `${buttonRect.top - editorRect.height - 5}px`;
					}
				} else {
					// Reset positioning when closing
					editor.style.position = '';
					editor.style.top = '';
					editor.style.left = '';
					editor.style.right = '';
				}

				e.stopPropagation();
				return;
			}

			const moveButton = e.target.closest('.feed-move-to-list button');
			if (moveButton) {
				const container = moveButton.closest('.freshvibes-container');
				const feedId = container.dataset.feedId;
				const sourceTabId = container.dataset.sourceTabId;
				const targetTabId = moveButton.dataset.targetTabId;

				if (!urls.moveFeed) {
					console.error('FreshVibesView: urls.moveFeed is not defined in the dataset. Cannot move feed.');
					return;
				}

				// Close the settings menu
				container.querySelector('.feed-settings-editor').classList.remove('active');

				// A simple client-side check to prevent moving a feed to a tab it's already in.
				const targetTab = state.layout.find(t => t.id === targetTabId);
				if (targetTab && targetTab.columns) {
					const feedExists = Object.values(targetTab.columns).some(feedIds =>
						feedIds.map(String).includes(String(feedId))
					);
					if (feedExists) {
						alert(tr.feed_already_in_tab || 'This feed is already in the target tab.');
						return;
					}
				}

				api(urls.moveFeed, { feed_id: feedId, source_tab_id: sourceTabId, target_tab_id: targetTabId })
					.then(data => {
						if (data.status === 'error') {
							alert(tr.error_moving_feed || 'Error moving feed. Please try again.');
							// Reload on error to ensure consistency
							location.reload();
							return;
						}
						if (data.status === 'success' && data.new_layout) {
							// Update with server response, which is the source of truth
							state.layout = assignUniqueSlugs(data.new_layout);
							state.allPlacedFeedIds = new Set(data.new_layout.flatMap(t =>
								Object.values(t.columns || {}).flat()
							).map(String));

							// Re-render the entire view to reflect the change
							render();
						}
					}).catch(error => {
						handleAPIError('Move feed', error);
						location.reload();
					});
				return;
			}

			if (e.target.closest('.feed-unread-badge')) {
				const badge = e.target.closest('.feed-unread-badge');
				const container = badge.closest('.freshvibes-container');
				const feedId = container.dataset.feedId;
				const feedData = state.feeds[feedId];
				const shouldConfirm = settings.confirmMarkRead !== '0';

				const performMarkRead = () => {
					api(urls.markFeedRead, { feed_id: feedId }).then(data => {
						if (data.status === 'success') {
							badge.remove();
							feedData.nbUnread = 0;
							container.querySelectorAll('.entry-item:not(.read)').forEach(li => li.classList.add('read'));
							feedData.entries.forEach(entry => entry.isRead = true);
							updateTabBadge(feedId);
						}
					}).catch(error => handleAPIError('Mark feed read', error));
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
				const shouldConfirm = settings.confirmMarkRead === '1';

				if (tabData && tabData.unread_count > 0) {
					const performMarkRead = () => {
						api(urls.markTabRead, { tab_id: tabId }).then(data => {
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
						}).catch(error => handleAPIError('Mark tab read', error));
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

					applyTabColors(tabEl, '', '');

					// Save: Send empty values to the server to signify a reset
					saveTabColors(tabId, '', '').then(data => {
						if (data.status === 'success') {
							// Reset color-picker to default computed style
							const tempTab = document.createElement('div');
							tempTab.className = 'freshvibes-tab';
							document.body.appendChild(tempTab);
							const defaultBg = window.getComputedStyle(tempTab).backgroundColor;
							document.body.removeChild(tempTab);

							const rgb = defaultBg.match(/\d+/g);
							if (rgb) {
								const hex = '#' + rgb.map(x => parseInt(x).toString(16).padStart(2, '0')).join('');
								resetColorInput(colorInput, hex);
							}
						}
					})
						.catch(error => handleAPIError('Reset tab color', error));;
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
					api(urls.saveFeedSettings, {
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
							resetColorInput(colorInput);
						}
					}).catch(error => handleAPIError('Reset feed color', error));
				}
			}
		});

		const activePickers = new Map();
		// Remove old handler if exists
		if (heightPickerHandler) {
			document.removeEventListener('click', heightPickerHandler);
		}
		heightPickerHandler = setupHeightPickerHandler(activePickers);
		document.addEventListener('click', heightPickerHandler);

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
				if (!entry) return;

				if (actionBtn.dataset.action === 'favorite') {
					if (!urls.bookmark) return;
					const newFav = !entry.isFavorite;
					api(urls.bookmark, { id: entryId, is_favorite: newFav ? 1 : 0, ajax: 1 })
						.then(() => {
							entry.isFavorite = newFav;
							actionBtn.classList.toggle('is-favorite', newFav);
							actionBtn.title = tr.mark_favorite || 'Toggle favourite';

							// Update the favorite indicator
							const indicator = entryItem.querySelector('.entry-favorite-indicator');
							if (indicator) {
								indicator.classList.toggle('is-favorite', newFav);
							}
						}).catch(error => handleAPIError('Toggle favorite', error));
					return;
				}

				if (!urls.markRead) return;

				const isCurrentlyRead = entry.isRead;
				const newReadState = !isCurrentlyRead;

				api(urls.markRead, { id: entryId, is_read: newReadState ? 1 : 0, ajax: 1 })
					.then(() => {
						entry.isRead = newReadState;
						entryItem.classList.toggle('read', newReadState);
						actionBtn.classList.toggle('is-read', newReadState);
						actionBtn.title = newReadState ? (tr.mark_unread || 'Mark as unread') : (tr.mark_read || 'Mark as read');

						if (feedData) {
							if (newReadState) { // Entry was marked as read
								feedData.nbUnread = Math.max(0, (feedData.nbUnread || 0) - 1);
							} else { // Entry was marked as unread
								feedData.nbUnread = (feedData.nbUnread || 0) + 1;
							}

							const container = document.querySelector(`.freshvibes-container[data-feed-id="${feedId}"]`);
							if (container) {
								const headerElement = container.querySelector('.freshvibes-container-header');
								if (headerElement) {
									updateUnreadBadge(headerElement, feedData.nbUnread);
								}
							}
							updateTabBadge(feedId);
						}
					}).catch(error => handleAPIError('Toggle read state', error));
			}
		});

		freshvibesView.addEventListener('click', e => {
			// This handler is specifically for entry links
			const entryLink = e.target.closest('.entry-link');

			// Ignore if the click is not on an entry link or is on one of the action buttons
			if (!entryLink || e.target.closest('.entry-actions')) {
				return;
			}

			const clickMode = settings.entryClickMode || 'modal';

			// For 'external' mode, we do nothing. The browser will follow the link's href
			// and `target="_blank"` will correctly open it in a new tab.
			if (clickMode === 'external') {
				const entryItem = entryLink.closest('.entry-item');
				const feedId = entryItem.dataset.feedId;
				const entryId = entryItem.dataset.entryId;
				const feedData = state.feeds[feedId];
				const entry = feedData?.entries?.find(e => String(e.id) === entryId);

				if (!entry || entry.isRead || !urls.markRead) {
					return; // Do nothing if entry is not found, already read, or URL is missing
				}

				// Mark as read via API
				api(urls.markRead, {
					id: entryId,
					is_read: 1,
					ajax: 1
				}).then(data => {
					if (data.status === 'error') return;

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
				}).catch(error => handleAPIError('Mark as read on click', error));
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

		const changeEventTarget = freshvibesView.getAttribute('data-layout') === 'vertical'
			? document.querySelector('.freshvibes-vertical-container')
			: tabsContainer;

		if (changeEventTarget) {
			changeEventTarget.addEventListener('change', e => {
				if (e.target.classList.contains('tab-icon-input') || e.target.classList.contains('tab-icon-color-input')) {
					const tabEl = e.target.closest('.freshvibes-tab');
					if (!tabEl) return;
					const tabId = tabEl.dataset.tabId;
					const iconInput = tabEl.querySelector('.tab-icon-input');
					const colorInput = tabEl.querySelector('.tab-icon-color-input');
					const iconVal = iconInput ? iconInput.value.trim() : '';
					const colorVal = colorInput ? colorInput.value : '#000000';

					api(urls.tabAction, { operation: 'set_icon', tab_id: tabId, icon: iconVal, color: colorVal }).then(data => {
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
					}).catch(error => handleAPIError('Update tab settings', error));
				} else if (e.target.classList.contains('tab-bg-color-input')) {
					const tabEl = e.target.closest('.freshvibes-tab');
					if (!tabEl) return;
					const tabId = tabEl.dataset.tabId;
					const bgColor = e.target.value;
					const fontColor = getContrastColor(bgColor);

					api(urls.tabAction, { operation: 'set_colors', tab_id: tabId, bg_color: bgColor, font_color: fontColor })
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
						}).catch(error => handleAPIError('Update tab settings', error));
				}
			});

			// Only add rename handler for tabs mode (vertical mode handles it separately)
			if (freshvibesView.getAttribute('data-layout') !== 'vertical') {
				tabsContainer.addEventListener('dblclick', handleTabRename);
			}

			// Use the appropriate container based on layout
			const inputContainer = freshvibesView.getAttribute('data-layout') === 'vertical'
				? freshvibesView
				: tabsContainer;

			inputContainer.addEventListener('input', e => {
				if (e.target.classList.contains('tab-bg-color-input')) {
					const tabEl = e.target.closest('.freshvibes-tab');
					if (!tabEl) return;
					const bgColor = e.target.value;
					// live preview by setting CSS variables
					applyTabColors(tabEl, bgColor);
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
				freshvibesView.addEventListener('click', e => {
					if (e.target.classList.contains('tab-icon-input')) {
						e.stopPropagation();
						activeIconInput = e.target;
						const rect = e.target.getBoundingClientRect();
						if (freshvibesView.getAttribute('data-layout') === 'vertical') {
							const container = document.querySelector('.freshvibes-vertical-container');
							const containerRect = container.getBoundingClientRect();
							iconPicker.style.left = `${rect.left - containerRect.left}px`;
							iconPicker.style.top = `${rect.bottom - containerRect.top + 5}px`;
						} else {
							iconPicker.style.left = `${rect.left}px`;
							iconPicker.style.top = `${rect.bottom + 5}px`;
						}
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
			freshvibesView.addEventListener('change', e => {
				const feedSettingsEditor = e.target.closest('.feed-settings-editor');
				if (!feedSettingsEditor) return;

				// Check if the changed element is one of our settings inputs
				if (e.target.matches('.feed-limit-select, .feed-fontsize-select, .feed-maxheight-input, .feed-header-color-input, .feed-display-mode-select')) {
					const container = feedSettingsEditor.closest('.freshvibes-container');
					const feedId = container.dataset.feedId;

					// --- Collect all current values from the editor ---
					const settingsToSave = {
						limit: feedSettingsEditor.querySelector('.feed-limit-select').value,
						fontSize: feedSettingsEditor.querySelector('.feed-fontsize-select').value,
						maxHeight: feedSettingsEditor.querySelector('.feed-maxheight-input').value,
						displayMode: feedSettingsEditor.querySelector('.feed-display-mode-select').value,
						headerColor: feedSettingsEditor.querySelector('.feed-header-color-input').value
					};

					// If the header color is the default placeholder, treat it as a reset.
					if (settingsToSave.headerColor === '#f0f0f0') {
						settingsToSave.headerColor = '';
					}

					// --- Live Preview Logic ---
					container.className = 'freshvibes-container'; // Reset classes
					container.classList.toggle('fontsize-xsmall', settingsToSave.fontSize === 'xsmall');
					container.classList.toggle('fontsize-small', settingsToSave.fontSize === 'small');
					container.classList.toggle('fontsize-large', settingsToSave.fontSize === 'large');
					container.classList.toggle('fontsize-xlarge', settingsToSave.fontSize === 'xlarge');
					container.classList.toggle('display-compact', settingsToSave.displayMode === 'compact');
					container.classList.toggle('display-detailed', settingsToSave.displayMode === 'detailed');
					const contentDiv = container.querySelector('.freshvibes-container-content');
					if (contentDiv) {
						if (!['unlimited', 'fit'].includes(settingsToSave.maxHeight)) {
							contentDiv.style.height = `${settingsToSave.maxHeight}px`;
						} else {
							contentDiv.style.height = '';
						}
					}

					// --- API Call ---
					saveFeedSettings(feedId, settingsToSave).then(data => {
						if (data.status === 'success') {
							const feed = state.feeds[feedId];
							const oldLimit = feed.currentLimit;
							const oldDisplayMode = feed.currentDisplayMode;

							// Update state from the saved settings
							feed.currentLimit = isNaN(parseInt(settingsToSave.limit, 10)) ? settingsToSave.limit : parseInt(settingsToSave.limit, 10);
							feed.currentFontSize = settingsToSave.fontSize;
							feed.currentMaxHeight = settingsToSave.maxHeight;
							feed.currentDisplayMode = settingsToSave.displayMode;
							feed.currentHeaderColor = settingsToSave.headerColor;

							// Reload if limit or display mode changes, as this affects the number/style of entries fetched.
							if (String(oldLimit) !== String(feed.currentLimit) || oldDisplayMode !== feed.currentDisplayMode) {
								reloadDebounce(() => location.reload(), 500);
							}
						}
					}).catch(error => handleAPIError('Save feed settings', error));
				}
			});

			// Handle middle-click on entries to mark as read
			freshvibesView.addEventListener('auxclick', e => {
				if (e.button !== 1) { // Only care about the middle mouse button
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

				if (!entry || entry.isRead || !urls.markRead) {
					return; // Do nothing if entry is not found, already read, or URL is missing
				}

				// Mark as read via API
				api(urls.markRead, {
					id: entryId,
					is_read: 1,
					ajax: 1
				}).then(data => {
					if (data.status === 'error') return;

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
				}).catch(error => handleAPIError('Mark as read on middle-click', error));
			});

			// Bulk settings modal (delegate "open" so it survives re-renders)
			const bulkSettingsModal = document.getElementById('bulk-settings-modal');
			if (bulkSettingsModal) {
				document.body.addEventListener('click', e => {
					if (e.target.closest('#bulk-settings-btn')) {
						bulkSettingsModal.classList.add('active');
						document.body.classList.add('modal-open');
					}
				});

				bulkSettingsModal.addEventListener('click', e => {
					if (e.target === bulkSettingsModal || e.target.closest('.fv-modal-close')) {
						bulkSettingsModal.classList.remove('active');
						document.body.classList.remove('modal-open');
					}
				});

				const feedColorInput = document.getElementById('bulk-feed-header-color');
				if (feedColorInput) {
					resetColorInput(feedColorInput);
					feedColorInput.dataset.reset = '';
					feedColorInput.addEventListener('input', () => {
						feedColorInput.dataset.reset = '';
					});
				}

				const tabColorInput = document.getElementById('bulk-tab-bg-color');
				if (tabColorInput) {
					resetColorInput(tabColorInput);
					tabColorInput.dataset.reset = '';
					tabColorInput.addEventListener('input', () => {
						tabColorInput.dataset.reset = '';
					});
				}

				// Apply bulk feed settings
				document.getElementById('apply-bulk-feed-settings')?.addEventListener('click', () => {
					const feedColorInput = document.getElementById('bulk-feed-header-color');
					const settings = {
						limit: document.getElementById('bulk-feed-limit').value,
						font_size: document.getElementById('bulk-feed-fontsize').value,
						display_mode: document.getElementById('bulk-feed-display').value,
						header_color: feedColorInput?.dataset.reset ? '' : feedColorInput.value,
						max_height: document.getElementById('bulk-feed-maxheight').value
					};

					if (confirm(tr.confirm_bulk_apply_feeds)) {
						api(urls.bulkApplyFeeds, settings)
							.then(() => {
								if (feedColorInput) feedColorInput.dataset.reset = '';
								alert(tr.bulk_apply_success_feeds);
								location.reload();
							})
							.catch(err => {
								console.error('Error applying bulk feed settings:', err);
								alert(tr.error_applying_settings || 'Error applying settings. Please try again.');
							});
					}
				});

				// Apply bulk tab settings
				document.getElementById('apply-bulk-tab-settings')?.addEventListener('click', () => {
					const tabColorInput = document.getElementById('bulk-tab-bg-color');
					const settings = {
						num_columns: document.getElementById('bulk-tab-columns').value,
						bg_color: tabColorInput?.dataset.reset ? '' : tabColorInput.value
					};

					if (confirm(tr.confirm_bulk_apply_tabs)) {
						api(urls.bulkApplyTabs, settings)
							.then(() => {
								if (tabColorInput) tabColorInput.dataset.reset = '';
								alert(tr.bulk_apply_success_tabs);
								location.reload();
							})
							.catch(err => {
								console.error('Error applying bulk tab settings:', err);
								alert(tr.error_applying_settings || 'Error applying settings. Please try again.');
							});
					}
				});

				// Reset all feed settings
				document.getElementById('reset-all-feed-settings')?.addEventListener('click', () => {
					if (confirm(tr.confirm_reset_all_feeds)) {
						api(urls.resetFeeds, {})
							.then(() => {
								alert(tr.bulk_reset_success_feeds);
								location.reload();
							})
							.catch(err => {
								console.error('Error resetting feed settings:', err);
								alert(tr.error_resetting_settings || 'Error resetting settings. Please try again.');
							});
					}
				});

				// Reset all tab settings
				document.getElementById('reset-all-tab-settings')?.addEventListener('click', () => {
					if (confirm(tr.confirm_reset_all_tabs)) {
						api(urls.resetTabs, {})
							.then(() => {
								alert(tr.bulk_reset_success_tabs);
								location.reload();
							})
							.catch(err => {
								console.error('Error resetting tab settings:', err);
								alert(tr.error_resetting_settings || 'Error resetting settings. Please try again.');
							});
					}
				});

				// Reset bulk color pickers to default
				bulkSettingsModal.querySelectorAll('.color-reset').forEach(btn => {
					btn.addEventListener('click', e => {
						const targetId = e.target.dataset.target;
						const colorInput = document.getElementById(targetId);
						if (colorInput) {
							resetColorInput(colorInput);
						}
					});
				});
			}
		}
	}


	// --- INITIALIZATION ---
	fetch(urls.getLayout)
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

	window.addEventListener('beforeunload', () => {
		document.removeEventListener('click', heightPickerHandler);
	});
}
