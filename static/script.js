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

	// --- DOM & CONFIG ---
	const isCategoryMode = settings.mode === 'categories';

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
						.catch(() => renderVerticalLayout());
				}
			});
		}

		// Initialize sortable for all columns in vertical mode
		setTimeout(() => {
			document
				.querySelectorAll('.freshvibes-vertical-container .freshvibes-column')
				.forEach(col => initializeSortable([col]));
		}, 100);
	}

	function setupVerticalLayoutHandlers() {
		// Handle mark all as read for sections
		document.querySelectorAll('.section-unread-count.clickable').forEach(badge => {
			badge.addEventListener('click', e => {
				e.stopPropagation();
				const tabId = badge.dataset.tabId;
				const tabData = state.layout.find(t => t.id === tabId);
				const shouldConfirm = settings.confirmMarkRead === '1';

				if (tabData && parseInt(badge.textContent) > 0) {
					const performMarkRead = () => {
						api(urls.markTabRead, { tab_id: tabId }).then(data => {
							if (data.status === 'success') {
								badge.textContent = '0';
								badge.style.display = 'none';
								// Update feeds in this section
								const section = badge.closest('.freshvibes-vertical-section');
								section.querySelectorAll('.freshvibes-container').forEach(container => {
									const unreadBadge = container.querySelector('.feed-unread-badge');
									if (unreadBadge) unreadBadge.remove();
									container.querySelectorAll('.entry-item:not(.read)').forEach(li => li.classList.add('read'));
								});
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
			});
		});

		// Handle settings button for sections (implement similar menu as tabs)
		document.querySelectorAll('.section-settings-button').forEach(button => {
			button.addEventListener('click', e => {
				e.stopPropagation();
				// You can implement a dropdown menu here similar to tab settings
				// For now, just a placeholder
				alert('Section settings not implemented yet');
			});
		});
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

	function renderTabContentInContainer(tab, columnsContainer) {
		const columns = Array.from({ length: tab.num_columns }, (_, i) => {
			const colDiv = document.createElement('div');
			colDiv.className = 'freshvibes-column';
			colDiv.dataset.columnId = `col${i + 1}`;
			columnsContainer.appendChild(colDiv);
			return colDiv;
		});

		const renderedFeeds = new Set();

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

		setTimeout(() => {
			initializeSortable(columns);
		}, 100);
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

			// Instead of calling refreshfeeds, reload the page via AJAX
			fetch(window.location.href, {
				headers: { 'X-Requested-With': 'XMLHttpRequest' }
			})
				.then(res => res.text())
				.then(html => {
					// Extract feeds data from the response
					const parser = new DOMParser();
					const doc = parser.parseFromString(html, 'text/html');
					const feedsScript = doc.getElementById('feeds-data-script');

					if (feedsScript) {
						const newFeedsData = JSON.parse(feedsScript.textContent);
						state.feeds = newFeedsData;
						renderTabs();
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

		if (modalFeed && feedData) {
			modalFeed.href = urls.feed.replace('f_', 'f_' + feedData.id);
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

			api(urls.markRead, { id: entry.id, ajax: 1, is_read: 1 }).catch(console.error);
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
					fetch(urls.markRead, {
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
		const displayDate = settings.dateMode === 'relative' ? entry.dateRelative : entry.dateShort;

		// Always include the indicator span, and toggle a class for visibility.
		const favoriteIndicatorHTML = `<span class="entry-favorite-indicator ${entry.isFavorite ? 'is-favorite' : ''}">${tr.icon_starred || '⭐'}</span>`;

		let entryHTML = '';
		if (feed.currentDisplayMode === 'tiny') {
			entryHTML = `
				<a class="entry-link" href="${entry.link}" target="_blank" rel="noopener noreferrer" data-entry-id="${entry.id}" data-feed-id="${feed.id}">
					<div class="entry-main">
						${favoriteIndicatorHTML}
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
							${favoriteIndicatorHTML}
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
	function api(url, body) {
		return fetch(url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
			body: new URLSearchParams({ ...body, '_csrf': csrfToken }),
		}).then(res => {
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
		}, error => {
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
			api(urls.setActiveTab, { tab_id: tabId }).catch(console.error);
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
				delay: 300,
				delayOnTouchOnly: true,
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
						api(urls.saveLayout, { layout: JSON.stringify(layoutData), tab_id: targetPanel.id }).catch(console.error);
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
				delay: 300,
				delayOnTouchOnly: true,
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
					api(urls.tabAction, { operation: 'reorder', tab_ids: newOrder.join(',') })
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

				api(urls.saveFeedSettings, {
					feed_id: feedId,
					limit: editor.querySelector('.feed-limit-select').value,
					font_size: editor.querySelector('.feed-fontsize-select').value,
					header_color: editor.querySelector('.feed-header-color-input').value,
					max_height: String(finalHeight),
					display_mode: editor.querySelector('.feed-display-mode-select').value,
				}).then(data => {
					if (data.status === 'success') {
						const feed = state.feeds[feedId];
						if (feed) {
							feed.currentMaxHeight = String(finalHeight);
						}
					}
				}).catch(error => console.error('Error saving feed settings:', error));
			}

			document.addEventListener('mousemove', doDrag);
			document.addEventListener('mouseup', stopDrag);
		});
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

					api(urls.tabAction, { operation: 'set_columns', tab_id: tabId, value: numCols }).then(data => {
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

						api(urls.tabAction, { operation: 'set_colors', tab_id: tabId, bg_color: '', font_color: '' })
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
				api(urls.tabAction, { operation: 'add' }).then(data => {
					if (data.status === 'success') {
						state.layout.push(data.new_tab);
						updateSlugURL(state, data.new_tab);
						render();
						activateTab(data.new_tab.id, false);
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
				const tab = button.closest('.freshvibes-tab');

				document.querySelectorAll('.tab-settings-menu.active').forEach(m => {
					if (m !== menu) {
						m.classList.remove('active');
					}
				});

				menu.classList.toggle('active');

				// Position menu to stay within viewport
				if (menu.classList.contains('active')) {
					// Reset inline styles
					menu.style.left = '';
					menu.style.right = '';

					// Get measurements after menu is visible
					const menuRect = menu.getBoundingClientRect();
					const tabRect = tab.getBoundingClientRect();
					const viewportWidth = window.innerWidth;

					// Calculate how much the menu overflows on the right
					const rightOverflow = menuRect.right - viewportWidth + 10; // 10px padding

					if (rightOverflow > 0) {
						// Shift menu left just enough to fit
						const currentRight = parseInt(window.getComputedStyle(menu).right) || 0;
						menu.style.right = `${currentRight + rightOverflow}px`;
					}

					// Check left edge
					const updatedRect = menu.getBoundingClientRect();
					if (updatedRect.left < 10) {
						// If it's off the left edge, position it from the left of the tab
						menu.style.right = 'auto';
						menu.style.left = `${10 - tabRect.left}px`;
					}
				}

				e.stopPropagation();
				return;
			}

			const columnsButton = e.target.closest('[data-columns]');
			if (columnsButton) {
				const numCols = columnsButton.dataset.columns;
				const tabId = columnsButton.closest('.freshvibes-tab').dataset.tabId;

				// Update active state immediately
				columnsButton.parentElement.querySelectorAll('button').forEach(btn => {
					btn.classList.toggle('active', btn.dataset.columns === numCols);
				});

				api(urls.tabAction, { operation: 'set_columns', tab_id: tabId, value: numCols }).then(data => {
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

				api(urls.moveFeed, { feed_id: feedId, source_tab_id: sourceTabId, target_tab_id: targetTabId })
					.then(data => {
						if (data.status === 'error') {
							alert(tr.error_moving_feed || 'Error moving feed. Please try again.');
							return;
						}
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
					api(urls.tabAction, { operation: 'set_colors', tab_id: tabId, bg_color: '', font_color: '' })
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
							colorInput.value = '#f0f0f0'; // A default light gray
						}
					}).catch(console.error);
				}
			}
		});

		let activeHeightPicker = null;
		document.addEventListener('click', e => {
			const heightInput = e.target.closest('.feed-maxheight-input');
			const pickerButton = e.target.closest('.fv-height-picker button');

			// If we clicked a picker button to select a value
			if (pickerButton) {
				e.stopPropagation();
				const picker = pickerButton.closest('.fv-height-picker');
				const input = picker.previousElementSibling;
				if (input) {
					input.value = pickerButton.dataset.value;
					input.dispatchEvent(new Event('change', { bubbles: true }));
				}
				picker.classList.remove('active');
				activeHeightPicker = null;
				return;
			}

			// If we clicked an input to open its picker
			if (heightInput) {
				e.stopPropagation();
				const picker = heightInput.nextElementSibling;
				// Close any other active picker before opening a new one
				if (activeHeightPicker && activeHeightPicker !== picker) {
					activeHeightPicker.classList.remove('active');
				}
				picker.classList.toggle('active');
				activeHeightPicker = picker.classList.contains('active') ? picker : null;
				return;
			}

			// If we clicked anywhere else, close the active picker
			if (activeHeightPicker) {
				activeHeightPicker.classList.remove('active');
				activeHeightPicker = null;
			}
		});

		let activeBulkHeightPicker = null;
		document.addEventListener('click', e => {
			const bulkHeightInput = e.target.closest('#bulk-feed-maxheight');
			const bulkPickerButton = e.target.closest('.fv-height-picker button[data-value]');

			// If we clicked a button inside the bulk picker to select a value
			if (bulkPickerButton && bulkPickerButton.closest('.height-picker-wrapper')?.querySelector('#bulk-feed-maxheight')) {
				e.stopPropagation();
				const picker = bulkPickerButton.closest('.fv-height-picker');
				const wrapper = picker.closest('.height-picker-wrapper');
				const input = wrapper?.querySelector('#bulk-feed-maxheight');
				if (input) {
					input.value = bulkPickerButton.dataset.value;
				}
				picker.classList.remove('active');
				activeBulkHeightPicker = null;
				return;
			}

			// If we clicked the bulk input to open its picker
			if (bulkHeightInput) {
				e.stopPropagation();
				const picker = bulkHeightInput.nextElementSibling;
				if (picker && picker.classList.contains('fv-height-picker')) {
					// Close any other active picker before opening a new one
					if (activeBulkHeightPicker && activeBulkHeightPicker !== picker) {
						activeBulkHeightPicker.classList.remove('active');
					}
					picker.classList.toggle('active');
					activeBulkHeightPicker = picker.classList.contains('active') ? picker : null;
				}
				return;
			}

			// If we clicked anywhere else, close the active bulk picker
			if (activeBulkHeightPicker) {
				activeBulkHeightPicker.classList.remove('active');
				activeBulkHeightPicker = null;
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
						}).catch(console.error);
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
				fetch(urls.markRead, {
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
				}).catch(console.error);
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
					})
					.catch(console.error);
			}
		});

		freshvibesView.addEventListener('change', e => {
			if (e.target.classList.contains('tab-icon-input') || e.target.classList.contains('tab-icon-color-input')) {
				const tabEl = e.target.closest('.freshvibes-tab');
				if (!tabEl) return;
				const id = tabEl.dataset.tabId;
				const icon = tabEl.querySelector('.tab-icon-input')?.value.trim() || '';
				const color = tabEl.querySelector('.tab-icon-color-input')?.value || '#000';
				api(urls.tabAction, { operation: 'set_icon', tab_id: id, icon, color })
					.then(data => {
						if (data.status === 'success') {
							const span = tabEl.querySelector('.tab-icon');
							span.textContent = icon;
							span.style.setProperty('--tab-icon-color', color);
							const t = state.layout.find(t => t.id === id);
							if (t) { t.icon = icon; t.icon_color = color; }
						}
					}).catch(console.error);
			}
			else if (e.target.classList.contains('tab-bg-color-input')) {
				const tabEl = e.target.closest('.freshvibes-tab');
				if (!tabEl) return;
				const id = tabEl.dataset.tabId;
				const bg = e.target.value;
				const fg = getContrastColor(bg);
				api(urls.tabAction, { operation: 'set_colors', tab_id: id, bg_color: bg, font_color: fg })
					.then(data => {
						if (data.status === 'success') {
							const t = state.layout.find(t => t.id === id);
							if (t) { t.bg_color = bg; t.font_color = fg; }
							tabEl.style.setProperty('--tab-bg-color', bg);
							tabEl.style.setProperty('--tab-font-color', fg);
						}
					}).catch(console.error);
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
					api(urls.tabAction, { operation: 'rename', tab_id: tabId, value: newName }).then(data => {
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

		freshvibesView.addEventListener('dblclick', e => {
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
				input.replaceWith(tabNameSpan);
				if (newName && newName !== oldName) {
					tabNameSpan.textContent = newName;
					api(urls.tabAction, { operation: 'rename', tab_id: tabId, value: newName })
						.then(data => {
							if (data.status === 'success') {
								const tabInState = state.layout.find(t => t.id === tabId);
								if (tabInState) tabInState.name = newName;
								updateSlugURL(state, tabInState);
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

		freshvibesView.addEventListener('input', e => {
			if (e.target.classList.contains('tab-bg-color-input')) {
				const tabEl = e.target.closest('.freshvibes-tab');
				if (!tabEl) return;
				const bg = e.target.value;
				tabEl.style.setProperty('--tab-bg-color', bg);
				tabEl.style.setProperty('--tab-font-color', getContrastColor(bg));
				tabEl.classList.add('has-custom-color');
			}
			if (e.target.classList.contains('tab-icon-color-input')) {
				const tabEl = e.target.closest('.freshvibes-tab');
				if (!tabEl) return;
				const icon = tabEl.querySelector('.tab-icon');
				if (icon) icon.style.setProperty('--tab-icon-color', e.target.value);
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
		freshvibesView.addEventListener('change', e => {
			const feedSettingsEditor = e.target.closest('.feed-settings-editor');
			if (!feedSettingsEditor) return;

			if (e.target.matches('.feed-limit-select, .feed-fontsize-select, .feed-maxheight-input, .feed-header-color-input, .feed-display-mode-select')) {
				const container = feedSettingsEditor.closest('.freshvibes-container');
				const feedId = container.dataset.feedId;

				// --- Live Preview Logic ---
				const fontSize = feedSettingsEditor.querySelector('.feed-fontsize-select').value;
				const maxHeight = feedSettingsEditor.querySelector('.feed-maxheight-input').value;
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
					if (!['unlimited', 'fit'].includes(maxHeight)) {
						contentDiv.style.height = `${maxHeight}px`;
					} else {
						contentDiv.style.height = '';
					}
				}

				// Only handle header color if it's the color input that changed
				if (e.target.classList.contains('feed-header-color-input')) {
					const headerColor = e.target.value;

					api(urls.saveFeedSettings, {
						feed_id: feedId,
						limit: feedSettingsEditor.querySelector('.feed-limit-select').value,
						font_size: fontSize,
						header_color: headerColor,
						max_height: maxHeight,
						display_mode: displayMode
					}).then(data => {
						if (data.status === 'success') {
							state.feeds[feedId].currentHeaderColor = headerColor;
						}
					}).catch(error => {
						console.error('Error saving feed settings:', error);
					});
				} else {
					// For other changes, save without header color
					api(urls.saveFeedSettings, {
						feed_id: feedId,
						limit: feedSettingsEditor.querySelector('.feed-limit-select').value,
						font_size: fontSize,
						max_height: maxHeight,
						display_mode: displayMode
					}).then(data => {
						if (data.status === 'success') {
							const feed = state.feeds[feedId];
							const oldLimit = feed.currentLimit;
							const oldDisplayMode = feed.currentDisplayMode;

							// Update state
							feed.currentLimit = isNaN(parseInt(feedSettingsEditor.querySelector('.feed-limit-select').value, 10))
								? feedSettingsEditor.querySelector('.feed-limit-select').value
								: parseInt(feedSettingsEditor.querySelector('.feed-limit-select').value, 10);
							feed.currentFontSize = fontSize;
							feed.currentMaxHeight = maxHeight;
							feed.currentDisplayMode = displayMode;

							// Reload if limit or display mode changes
							if (String(oldLimit) !== String(feed.currentLimit) || oldDisplayMode !== displayMode) {
								location.reload();
							}
						}
					}).catch(error => {
						console.error('Error saving feed settings:', error);
					});
				}
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
			fetch(urls.markRead, {
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

			// Bulk settings modal (delegate “open” so it survives re-renders)
			const bulkSettingsModal = document.getElementById('bulk-settings-modal');
			if (bulkSettingsModal) {
				document.body.addEventListener('click', e => {
					if (e.target.closest('#bulk-settings-btn')) {
						bulkSettingsModal.classList.add('active');
						document.body.classList.add('modal-open');
					}
				});
			}

			bulkSettingsModal.addEventListener('click', e => {
				if (e.target === bulkSettingsModal || e.target.closest('.fv-modal-close')) {
					bulkSettingsModal.classList.remove('active');
					document.body.classList.remove('modal-open');
				}
			});

			const feedColorInput = document.getElementById('bulk-feed-header-color');
			if (feedColorInput) {
				feedColorInput.value = '#f0f0f0';
				feedColorInput.dataset.reset = '';
				feedColorInput.addEventListener('input', () => {
					feedColorInput.dataset.reset = '';
				});
			}

			const tabColorInput = document.getElementById('bulk-tab-bg-color');
			if (tabColorInput) {
				tabColorInput.value = '#f0f0f0';
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
						colorInput.value = '#f0f0f0';
						colorInput.dataset.reset = '1';
					}
				});
			});
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
}
