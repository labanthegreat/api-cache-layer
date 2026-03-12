/**
 * API Cache Layer – Admin JavaScript.
 *
 * Handles flush, analytics, cache rules, browser, warmer, monitoring,
 * keyboard shortcuts, drag-and-drop, animated counters, and auto-refresh.
 *
 * @package API_Cache_Layer
 */

/* global jQuery, aclAdmin */

(function ($) {
	'use strict';

	// =====================================================
	// TOAST NOTIFICATION SYSTEM
	// =====================================================
	var $toastContainer = null;

	function getToastContainer() {
		if (!$toastContainer || !$toastContainer.length) {
			$toastContainer = $('<div class="acl-toast-container"></div>').appendTo('body');
		}
		return $toastContainer;
	}

	function showToast(message, type) {
		type = type || 'success';
		var icon = 'yes-alt';
		if (type === 'error') icon = 'dismiss';
		else if (type === 'warning') icon = 'warning';

		var $toast = $(
			'<div class="acl-toast acl-toast--' + type + '">' +
				'<span class="dashicons dashicons-' + icon + '"></span>' +
				'<span>' + message + '</span>' +
				'<div class="acl-toast-progress"></div>' +
			'</div>'
		);

		getToastContainer().append($toast);

		setTimeout(function () {
			$toast.addClass('acl-toast--out');
			setTimeout(function () { $toast.remove(); }, 300);
		}, 4000);
	}

	// =====================================================
	// CUSTOM CONFIRM MODAL
	// =====================================================
	function aclConfirm(message, onConfirm, options) {
		options = options || {};
		var isDanger = options.danger || false;

		var $modal = $(
			'<div class="acl-confirm-modal">' +
				'<div class="acl-confirm-modal-box">' +
					'<span class="dashicons dashicons-warning acl-confirm-icon' + (isDanger ? ' acl-confirm-icon--danger' : '') + '"></span>' +
					'<p>' + message + '</p>' +
					'<div class="acl-confirm-actions">' +
						'<button type="button" class="button acl-confirm-cancel">' + (options.cancelText || aclAdmin.i18n.cancel) + '</button>' +
						'<button type="button" class="button ' + (isDanger ? 'button-link-delete' : 'button-primary') + ' acl-confirm-ok">' + (options.confirmText || aclAdmin.i18n.confirmBtn) + '</button>' +
					'</div>' +
				'</div>' +
			'</div>'
		);

		$('body').append($modal);

		$modal.on('click', '.acl-confirm-ok', function () {
			$modal.remove();
			if (typeof onConfirm === 'function') onConfirm();
		});

		$modal.on('click', '.acl-confirm-cancel', function () {
			$modal.remove();
		});

		$modal.on('click', function (e) {
			if (e.target === $modal[0]) $modal.remove();
		});

		// Escape key closes confirm modal
		$(document).one('keydown.aclConfirm', function (e) {
			if (e.key === 'Escape') {
				$modal.remove();
			}
		});
	}

	// =====================================================
	// LOADING SPINNER HELPERS
	// =====================================================
	function btnLoading($btn) {
		$btn.addClass('acl-btn-loading').prop('disabled', true);
	}

	function btnReset($btn) {
		$btn.removeClass('acl-btn-loading').prop('disabled', false);
	}

	// =====================================================
	// DOUBLE-SUBMIT PREVENTION
	// =====================================================
	var submittingActions = {};

	function guardAction(key, fn) {
		if (submittingActions[key]) return;
		submittingActions[key] = true;
		var done = function () { delete submittingActions[key]; };
		fn(done);
	}

	// =====================================================
	// DEBOUNCE UTILITY
	// =====================================================
	function debounce(fn, delay) {
		var timer;
		return function () {
			var ctx = this, args = arguments;
			clearTimeout(timer);
			timer = setTimeout(function () { fn.apply(ctx, args); }, delay);
		};
	}

	// =====================================================
	// ANIMATED NUMBER UPDATE (with easing)
	// =====================================================
	function animateValue($el, newText) {
		var oldText = $el.text().trim();
		if (oldText === newText) return;

		var oldNum = parseFloat(oldText.replace(/[^0-9.-]/g, ''));
		var newNum = parseFloat(newText.replace(/[^0-9.-]/g, ''));
		var suffix = newText.replace(/[0-9,.-]/g, '').trim();

		if (!isNaN(oldNum) && !isNaN(newNum) && oldNum !== newNum) {
			var steps = 30;
			var step = 0;
			var diff = newNum - oldNum;
			var isInt = Number.isInteger(newNum) && newText.indexOf('.') === -1;
			var hasCommas = newText.indexOf(',') !== -1;

			var interval = setInterval(function () {
				step++;
				// Ease-out cubic for smooth deceleration
				var progress = step / steps;
				progress = 1 - Math.pow(1 - progress, 3);
				var current = oldNum + diff * progress;

				if (isInt) {
					current = Math.round(current);
					if (hasCommas) {
						$el.text(current.toLocaleString() + suffix);
					} else {
						$el.text(current + suffix);
					}
				} else {
					var decimals = (newText.split('.')[1] || '').replace(/[^0-9]/g, '').length;
					if (hasCommas) {
						$el.text(current.toLocaleString(undefined, {minimumFractionDigits: decimals, maximumFractionDigits: decimals}) + suffix);
					} else {
						$el.text(current.toFixed(decimals) + suffix);
					}
				}

				if (step >= steps) {
					clearInterval(interval);
					$el.text(newText);
				}
			}, 20);

			// Flash the parent card
			$el.closest('.acl-stat-card, .acl-dashboard-card').addClass('acl-stat-updated');
			setTimeout(function () {
				$el.closest('.acl-stat-card, .acl-dashboard-card').removeClass('acl-stat-updated');
			}, 600);
		} else {
			$el.text(newText);
		}
	}

	// =====================================================
	// ANIMATED COUNTER (initial page load)
	// =====================================================
	function animateCounterOnLoad($el) {
		var finalText = $el.text().trim();
		var finalNum = parseFloat(finalText.replace(/[^0-9.-]/g, ''));
		if (isNaN(finalNum) || finalNum === 0) return;

		var suffix = finalText.replace(/[0-9,.-]/g, '').trim();
		var isInt = Number.isInteger(finalNum) && finalText.indexOf('.') === -1;
		var hasCommas = finalText.indexOf(',') !== -1;
		var steps = 40;
		var step = 0;

		$el.text('0' + suffix);

		var interval = setInterval(function () {
			step++;
			var progress = step / steps;
			// Ease-out exponential
			progress = 1 - Math.pow(2, -10 * progress);
			var current = finalNum * progress;

			if (isInt) {
				current = Math.round(current);
				$el.text((hasCommas ? current.toLocaleString() : current) + suffix);
			} else {
				var decimals = (finalText.split('.')[1] || '').replace(/[^0-9]/g, '').length;
				$el.text(current.toFixed(decimals) + suffix);
			}

			if (step >= steps) {
				clearInterval(interval);
				$el.text(finalText);
			}
		}, 20);
	}

	// =====================================================
	// HIT RATE RING CHART ANIMATION
	// =====================================================
	function animateRingChart() {
		var $ring = $('.acl-ring-fill');
		if (!$ring.length) return;

		var rate = parseFloat($ring.data('rate') || 0);
		var circumference = 408.41;
		var offset = circumference - (rate / 100) * circumference;

		// Set initial state
		$ring.css('stroke-dashoffset', circumference);

		// Trigger animation after brief delay
		setTimeout(function () {
			$ring.css('stroke-dashoffset', offset);
		}, 100);

		// Color based on rate
		$ring.removeClass('acl-ring-success acl-ring-warning acl-ring-danger');
		if (rate >= 80) {
			$ring.addClass('acl-ring-success');
		} else if (rate >= 50) {
			$ring.addClass('acl-ring-warning');
		} else {
			$ring.addClass('acl-ring-danger');
		}
	}

	/**
	 * Utility to format bytes.
	 */
	function formatBytes(bytes) {
		if (bytes < 1024) return bytes + ' B';
		if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
		return (bytes / 1048576).toFixed(2) + ' MB';
	}

	/**
	 * Utility to format seconds.
	 */
	function formatDuration(seconds) {
		if (seconds <= 0) return aclAdmin.i18n.expired;
		if (seconds < 60) return seconds + 's';
		if (seconds < 3600) return Math.round(seconds / 60) + 'm';
		return (seconds / 3600).toFixed(1) + 'h';
	}

	/**
	 * Copy text to clipboard.
	 */
	function copyToClipboard(text) {
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(function () {
				showToast(aclAdmin.i18n.copiedToClipboard, 'success');
			});
		} else {
			var $temp = $('<input>');
			$('body').append($temp);
			$temp.val(text).select();
			document.execCommand('copy');
			$temp.remove();
			showToast(aclAdmin.i18n.copiedToClipboard, 'success');
		}
	}

	/**
	 * Helper for AJAX calls.
	 */
	function aclAjax(action, data, $status) {
		data = data || {};
		data.action = action;
		data.nonce = aclAdmin.nonce;

		return $.ajax({
			url: aclAdmin.ajaxUrl,
			type: 'POST',
			data: data,
			dataType: 'json'
		}).fail(function () {
			if ($status && $status.length) {
				$status.removeClass('loading success').addClass('error').text(aclAdmin.i18n.error);
			}
			showToast(aclAdmin.i18n.error, 'error');
		});
	}

	$(function () {

		// =====================================================
		// INITIAL PAGE LOAD ANIMATIONS
		// =====================================================
		$('.acl-dashboard-card-value, .acl-stat-value').each(function () {
			animateCounterOnLoad($(this));
		});

		animateRingChart();

		// =====================================================
		// KEYBOARD SHORTCUTS
		// =====================================================
		$(document).on('keydown', function (e) {
			// Ctrl/Cmd+F to flush cache (only when not in input)
			if ((e.ctrlKey || e.metaKey) && e.key === 'f' && !$(e.target).is('input, textarea, select')) {
				// Only intercept if we're on the ACL settings page
				if ($('#acl-flush-cache').length || $('#acl-quick-flush').length) {
					e.preventDefault();
					var $flushBtn = $('#acl-quick-flush').length ? $('#acl-quick-flush') : $('#acl-flush-cache');
					$flushBtn.trigger('click');
				}
			}

			// Escape to close modals
			if (e.key === 'Escape') {
				var $modal = $('.acl-modal:visible');
				if ($modal.length) {
					$modal.addClass('acl-modal-closing');
					setTimeout(function () { $modal.hide().removeClass('acl-modal-closing'); }, 200);
				}
			}
		});

		// =====================================================
		// FLUSH CACHE (Settings tab + Quick Action)
		// =====================================================
		var $flushBtn = $('#acl-flush-cache');
		var $flushStatus = $('#acl-flush-status');

		function doFlushCache() {
			aclConfirm(aclAdmin.i18n.confirm, function () {
				guardAction('flush', function (done) {
					var $btn = $('#acl-flush-cache').length ? $('#acl-flush-cache') : $('#acl-quick-flush');
					btnLoading($btn);
					$flushStatus.removeClass('success error').addClass('loading').text(aclAdmin.i18n.flushing);

					aclAjax('acl_flush_cache', {}, $flushStatus)
						.done(function (response) {
							if (response.success) {
								$flushStatus.removeClass('loading error').addClass('success').text('');
								showToast(response.data.message, 'success');
								$('.acl-stat-value').each(function () {
									var $el = $(this);
									var label = $el.next('.acl-stat-label').text().trim().toLowerCase();
									if (label === 'hit rate') {
										animateValue($el, '0%');
									} else if (label === 'total size') {
										animateValue($el, '0 B');
									} else {
										animateValue($el, '0');
									}
								});
								// Update dashboard cards
								$('.acl-dashboard-card-value').each(function () {
									animateValue($(this), '0');
								});
							} else {
								$flushStatus.removeClass('loading success').addClass('error').text('');
								showToast(response.data && response.data.message ? response.data.message : aclAdmin.i18n.error, 'error');
							}
						})
						.always(function () {
							btnReset($btn);
							btnReset($('#acl-quick-flush'));
							done();
						});
				});
			}, { danger: true, confirmText: aclAdmin.i18n.flushCache });
		}

		if ($flushBtn.length) {
			$flushBtn.on('click', function (e) {
				e.preventDefault();
				doFlushCache();
			});
		}

		// Quick action buttons
		$('#acl-quick-flush').on('click', function (e) {
			e.preventDefault();
			doFlushCache();
		});

		$('#acl-quick-warm').on('click', function (e) {
			e.preventDefault();
			var $btn = $(this);
			guardAction('quick-warm', function (done) {
				btnLoading($btn);
				aclAjax('acl_warm_cache')
					.done(function (response) {
						if (response.success) {
							showToast(response.data.message, 'success');
						} else {
							showToast(response.data.message || aclAdmin.i18n.error, 'error');
						}
					})
					.always(function () {
						btnReset($btn);
						done();
					});
			});
		});

		$('#acl-quick-export').on('click', function (e) {
			e.preventDefault();
			var $btn = $(this);
			guardAction('quick-export', function (done) {
				btnLoading($btn);
				aclAjax('acl_export_csv', { days: 30 })
					.done(function (response) {
						if (!response.success) return;
						var blob = new Blob([response.data.csv], { type: 'text/csv' });
						var link = document.createElement('a');
						link.href = URL.createObjectURL(blob);
						link.download = response.data.filename;
						document.body.appendChild(link);
						link.click();
						document.body.removeChild(link);
						showToast(aclAdmin.i18n.analyticsExported, 'success');
					})
					.always(function () {
						btnReset($btn);
						done();
					});
			});
		});

		// =====================================================
		// ANALYTICS TAB
		// =====================================================
		var analyticsAutoRefreshInterval = null;

		$('#acl-refresh-analytics').on('click', function () {
			var $btn = $(this);
			guardAction('analytics', function (done) {
				btnLoading($btn);
				var days = parseInt($('#acl-analytics-period').val(), 10) || 7;
				loadAnalytics(days, function () {
					btnReset($btn);
					done();
				});
			});
		});

		// Auto-refresh analytics
		$('#acl-analytics-auto-refresh').on('change', function () {
			if ($(this).is(':checked')) {
				startAnalyticsAutoRefresh();
			} else {
				stopAnalyticsAutoRefresh();
			}
		});

		$('#acl-analytics-refresh-interval').on('change', function () {
			if ($('#acl-analytics-auto-refresh').is(':checked')) {
				startAnalyticsAutoRefresh();
			}
		});

		function startAnalyticsAutoRefresh() {
			stopAnalyticsAutoRefresh();
			var seconds = parseInt($('#acl-analytics-refresh-interval').val(), 10) || 30;
			analyticsAutoRefreshInterval = setInterval(function () {
				var days = parseInt($('#acl-analytics-period').val(), 10) || 7;
				loadAnalytics(days);
			}, seconds * 1000);
		}

		function stopAnalyticsAutoRefresh() {
			if (analyticsAutoRefreshInterval) {
				clearInterval(analyticsAutoRefreshInterval);
				analyticsAutoRefreshInterval = null;
			}
		}

		function loadAnalytics(days, callback) {
			aclAjax('acl_get_analytics', { days: days })
				.done(function (response) {
					if (!response.success) return;
					var d = response.data;

					animateValue($('#acl-a-requests'), d.summary.total_requests.toLocaleString());
					animateValue($('#acl-a-hitrate'), d.summary.hit_rate + '%');
					animateValue($('#acl-a-cached-time'), d.summary.avg_cached_time + 'ms');
					animateValue($('#acl-a-uncached-time'), d.summary.avg_uncached_time + 'ms');
					animateValue($('#acl-a-time-saved'), d.summary.time_saved_ms.toLocaleString() + 'ms');
				})
				.always(function () {
					if (typeof callback === 'function') callback();
				});
		}

		// Export CSV
		$('#acl-export-csv').on('click', function () {
			var $btn = $(this);
			guardAction('export', function (done) {
				btnLoading($btn);
				var days = parseInt($('#acl-analytics-period').val(), 10) || 30;

				aclAjax('acl_export_csv', { days: days })
					.done(function (response) {
						if (!response.success) return;

						var blob = new Blob([response.data.csv], { type: 'text/csv' });
						var link = document.createElement('a');
						link.href = URL.createObjectURL(blob);
						link.download = response.data.filename;
						document.body.appendChild(link);
						link.click();
						document.body.removeChild(link);
						showToast(aclAdmin.i18n.csvExported, 'success');
					})
					.always(function () {
						btnReset($btn);
						done();
					});
			});
		});

		// =====================================================
		// CACHE RULES TAB
		// =====================================================
		var $ruleEditor = $('#acl-rule-editor');

		// Add Rule button
		$('#acl-add-rule').on('click', function () {
			resetRuleForm();
			$('#acl-rule-editor-title').text(aclAdmin.i18n.addCacheRule);
			$ruleEditor.show();
		});

		// Cancel button
		$('#acl-cancel-rule').on('click', function () {
			$ruleEditor.addClass('acl-modal-closing');
			setTimeout(function () { $ruleEditor.hide().removeClass('acl-modal-closing'); }, 200);
		});

		// Close modal on backdrop click
		$ruleEditor.on('click', function (e) {
			if (e.target === this) {
				$ruleEditor.addClass('acl-modal-closing');
				setTimeout(function () { $ruleEditor.hide().removeClass('acl-modal-closing'); }, 200);
			}
		});

		// Edit Rule
		$(document).on('click', '.acl-edit-rule', function () {
			var rule = $(this).data('rule');
			if (typeof rule === 'string') {
				try { rule = JSON.parse(rule); } catch (ex) { return; }
			}
			populateRuleForm(rule);
			$('#acl-rule-editor-title').text(aclAdmin.i18n.editCacheRule);
			$ruleEditor.show();
		});

		// Delete Rule
		$(document).on('click', '.acl-delete-rule', function () {
			var $row = $(this).closest('tr');
			var ruleId = $(this).data('id');
			var $btn = $(this);

			aclConfirm(aclAdmin.i18n.confirmDelete, function () {
				guardAction('delete-rule-' + ruleId, function (done) {
					btnLoading($btn);

					aclAjax('acl_delete_rule', { rule_id: ruleId })
						.done(function (response) {
							if (response.success) {
								$row.fadeOut(300, function () { $row.remove(); });
								showToast(aclAdmin.i18n.deleted, 'success');
							} else {
								btnReset($btn);
								showToast(response.data.message || aclAdmin.i18n.error, 'error');
							}
						})
						.fail(function () {
							btnReset($btn);
						})
						.always(function () {
							done();
						});
				});
			}, { danger: true, confirmText: aclAdmin.i18n.delete });
		});

		// Save Rule
		$('#acl-save-rule').on('click', function () {
			var $status = $('#acl-rule-status');
			var $btn = $(this);

			guardAction('save-rule', function (done) {
				btnLoading($btn);
				$status.removeClass('success error').addClass('loading').text(aclAdmin.i18n.saving);

				var data = {
					rule_id: $('#acl-rule-id').val(),
					route_pattern: $('#acl-rule-route').val(),
					ttl: $('#acl-rule-ttl').val(),
					enabled: $('#acl-rule-enabled').is(':checked') ? 1 : 0,
					vary_by_query_params: $('#acl-rule-vary-params').val(),
					vary_by_user_role: $('#acl-rule-vary-role').is(':checked') ? 1 : 0,
					vary_by_headers: $('#acl-rule-vary-headers').val(),
					skip_params: $('#acl-rule-skip-params').val(),
					stale_ttl: $('#acl-rule-stale-ttl').val(),
					tags: $('#acl-rule-tags').val(),
					rate_limit: $('#acl-rule-rate-limit').val(),
					rate_limit_window: $('#acl-rule-rate-window').val(),
					priority: $('#acl-rule-priority').val()
				};

				aclAjax('acl_save_rule', data, $status)
					.done(function (response) {
						if (response.success) {
							$status.removeClass('loading error').addClass('success').text('');
							showToast(aclAdmin.i18n.saved, 'success');
							setTimeout(function () {
								window.location.reload();
							}, 800);
						} else {
							$status.removeClass('loading success').addClass('error').text('');
							showToast(response.data.message || aclAdmin.i18n.error, 'error');
							btnReset($btn);
						}
					})
					.fail(function () {
						btnReset($btn);
					})
					.always(function () {
						done();
					});
			});
		});

		// Toggle rule status inline
		$(document).on('change', '.acl-rule-toggle-status', function () {
			var $toggle = $(this);
			var ruleId = $toggle.data('id');
			var ruleData = $toggle.data('rule');
			if (typeof ruleData === 'string') {
				try { ruleData = JSON.parse(ruleData); } catch (ex) { return; }
			}
			ruleData.enabled = $toggle.is(':checked') ? 1 : 0;

			aclAjax('acl_save_rule', {
				rule_id: ruleData.id,
				route_pattern: ruleData.route_pattern,
				ttl: ruleData.ttl,
				enabled: ruleData.enabled,
				vary_by_query_params: ruleData.vary_by_query_params || '',
				vary_by_user_role: ruleData.vary_by_user_role ? 1 : 0,
				vary_by_headers: ruleData.vary_by_headers || '',
				skip_params: ruleData.skip_params || '',
				stale_ttl: ruleData.stale_ttl || 0,
				tags: ruleData.tags || '',
				rate_limit: ruleData.rate_limit || 0,
				rate_limit_window: ruleData.rate_limit_window || 60,
				priority: ruleData.priority || 10
			}).done(function (response) {
				if (response.success) {
					var $badge = $toggle.closest('tr').find('.acl-status-badge');
					if (ruleData.enabled) {
						$badge.removeClass('acl-status-inactive').addClass('acl-status-active').text(aclAdmin.i18n.active);
					} else {
						$badge.removeClass('acl-status-active').addClass('acl-status-inactive').text(aclAdmin.i18n.inactive);
					}
					showToast(aclAdmin.i18n.ruleStatusUpdated, 'success');
				}
			});
		});

		function resetRuleForm() {
			$('#acl-rule-id').val('');
			$('#acl-rule-route').val('');
			$('#acl-rule-ttl').val('3600');
			$('#acl-rule-enabled').prop('checked', true);
			$('#acl-rule-vary-params').val('');
			$('#acl-rule-vary-role').prop('checked', false);
			$('#acl-rule-vary-headers').val('');
			$('#acl-rule-skip-params').val('');
			$('#acl-rule-stale-ttl').val('0');
			$('#acl-rule-tags').val('');
			$('#acl-rule-rate-limit').val('0');
			$('#acl-rule-rate-window').val('60');
			$('#acl-rule-priority').val('10');
			$('#acl-rule-status').text('');
		}

		function populateRuleForm(rule) {
			$('#acl-rule-id').val(rule.id || '');
			$('#acl-rule-route').val(rule.route_pattern || '');
			$('#acl-rule-ttl').val(rule.ttl || 3600);
			$('#acl-rule-enabled').prop('checked', !!rule.enabled);
			$('#acl-rule-vary-params').val(rule.vary_by_query_params || '');
			$('#acl-rule-vary-role').prop('checked', !!rule.vary_by_user_role);
			$('#acl-rule-vary-headers').val(rule.vary_by_headers || '');
			$('#acl-rule-skip-params').val(rule.skip_params || '');
			$('#acl-rule-stale-ttl').val(rule.stale_ttl || 0);
			$('#acl-rule-tags').val(rule.tags || '');
			$('#acl-rule-rate-limit').val(rule.rate_limit || 0);
			$('#acl-rule-rate-window').val(rule.rate_limit_window || 60);
			$('#acl-rule-priority').val(rule.priority || 10);
			$('#acl-rule-status').text('');
		}

		// =====================================================
		// RULE DRAG-AND-DROP REORDERING
		// =====================================================
		var dragSrcRow = null;

		$(document).on('dragstart', '.acl-drag-handle', function (e) {
			dragSrcRow = $(this).closest('tr');
			dragSrcRow.addClass('acl-dragging');
			e.originalEvent.dataTransfer.effectAllowed = 'move';
			e.originalEvent.dataTransfer.setData('text/plain', '');
		});

		$(document).on('dragover', '#acl-rules-table tbody tr', function (e) {
			e.preventDefault();
			e.originalEvent.dataTransfer.dropEffect = 'move';
			$('#acl-rules-table tbody tr').removeClass('acl-drag-over');
			$(this).addClass('acl-drag-over');
		});

		$(document).on('drop', '#acl-rules-table tbody tr', function (e) {
			e.preventDefault();
			var $target = $(this);
			$('#acl-rules-table tbody tr').removeClass('acl-drag-over');

			if (dragSrcRow && dragSrcRow[0] !== $target[0]) {
				if (dragSrcRow.index() < $target.index()) {
					$target.after(dragSrcRow);
				} else {
					$target.before(dragSrcRow);
				}
				// Update priority values based on new order
				$('#acl-rules-table tbody tr').each(function (idx) {
					$(this).find('.acl-rule-priority-val').text(idx + 1);
				});
				showToast(aclAdmin.i18n.ruleOrderUpdated, 'warning');
			}
		});

		$(document).on('dragend', '.acl-drag-handle', function () {
			if (dragSrcRow) {
				dragSrcRow.removeClass('acl-dragging');
				dragSrcRow = null;
			}
			$('#acl-rules-table tbody tr').removeClass('acl-drag-over');
		});

		// =====================================================
		// CACHE BROWSER TAB
		// =====================================================
		var $browserBody = $('#acl-browser-body');
		var browserPage = 1;
		var browserPerPage = 20;
		var allBrowserEntries = [];

		if ($browserBody.length) {
			loadCacheEntries();
		}

		$('#acl-browser-refresh').on('click', function () {
			var $btn = $(this);
			guardAction('browser-refresh', function (done) {
				btnLoading($btn);
				browserPage = 1;
				loadCacheEntries('', function () {
					btnReset($btn);
					done();
				});
			});
		});

		// Debounced search input
		$('#acl-browser-search').on('keyup', debounce(function () {
			browserPage = 1;
			loadCacheEntries($(this).val());
		}, 350));

		// Copy cache key to clipboard
		$(document).on('click', '.acl-copy-key', function (e) {
			e.stopPropagation();
			var key = $(this).data('key');
			copyToClipboard(key);
		});

		// Invalidate single entry from browser
		$(document).on('click', '.acl-invalidate-entry', function () {
			var key = $(this).data('key');
			var $row = $(this).closest('tr');
			var $btn = $(this);

			guardAction('invalidate-' + key, function (done) {
				btnLoading($btn);
				aclAjax('acl_flush_cache', { route: key })
					.done(function (response) {
						if (response.success) {
							$row.fadeOut(300, function () { $row.remove(); });
							showToast(aclAdmin.i18n.entryInvalidated, 'success');
						}
					})
					.always(function () {
						btnReset($btn);
						done();
					});
			});
		});

		// Bulk flush by pattern
		$('#acl-bulk-flush').on('click', function () {
			var pattern = $('#acl-bulk-pattern').val().trim();
			if (!pattern) {
				showToast(aclAdmin.i18n.enterPattern, 'warning');
				return;
			}
			var $btn = $(this);

			aclConfirm(aclAdmin.i18n.confirmFlushPattern.replace('%s', pattern), function () {
				guardAction('bulk-flush', function (done) {
					btnLoading($btn);
					aclAjax('acl_flush_cache', { route: pattern })
						.done(function (response) {
							if (response.success) {
								showToast(response.data.message, 'success');
								browserPage = 1;
								loadCacheEntries($('#acl-browser-search').val());
							}
						})
						.always(function () {
							btnReset($btn);
							done();
						});
				});
			}, { danger: true, confirmText: aclAdmin.i18n.flushMatching });
		});

		// Pagination click
		$(document).on('click', '.acl-browser-page-btn', function () {
			browserPage = parseInt($(this).data('page'), 10);
			renderBrowserPage();
		});

		function loadCacheEntries(search, callback) {
			$browserBody.html('<tr><td colspan="7" style="text-align:center;padding:24px;"><span class="acl-spinner"></span> ' + aclAdmin.i18n.loadingEntries + '</td></tr>');

			aclAjax('acl_get_cache_entries', { search: search || '' })
				.done(function (response) {
					if (!response.success || !response.data.entries.length) {
						$browserBody.html('<tr><td colspan="7" style="text-align:center;padding:24px;color:var(--acl-text-muted);">' + aclAdmin.i18n.noData + '</td></tr>');
						$('#acl-browser-pagination').empty();
						return;
					}

					allBrowserEntries = response.data.entries;
					browserPage = 1;
					renderBrowserPage();
				})
				.always(function () {
					if (typeof callback === 'function') callback();
				});
		}

		function renderBrowserPage() {
			var total = allBrowserEntries.length;
			var totalPages = Math.ceil(total / browserPerPage);
			var start = (browserPage - 1) * browserPerPage;
			var end = Math.min(start + browserPerPage, total);
			var entries = allBrowserEntries.slice(start, end);

			var html = '';
			entries.forEach(function (entry) {
				var ttlClass = '';
				if (entry.ttl_left < 60) ttlClass = 'acl-ttl-critical';
				else if (entry.ttl_left < 300) ttlClass = 'acl-ttl-warning';

				var ttlWidth = entry.status === 'active' ? Math.min(100, Math.max(5, (entry.ttl_left / 3600) * 100)) : 0;

				html += '<tr>';
				html += '<td><span class="acl-copy-key" data-key="' + entry.key + '" title="' + aclAdmin.i18n.clickToCopy + '">' + entry.key.substring(0, 24) + '... <span class="dashicons dashicons-clipboard"></span></span></td>';
				html += '<td class="acl-route-cell" title="' + entry.route + '">' + entry.route + '</td>';
				html += '<td><span class="acl-status-badge ' + (entry.status === 'active' ? 'acl-status-active' : 'acl-status-inactive') + '">' + entry.status + '</span></td>';
				html += '<td>' + entry.size_fmt + '</td>';
				html += '<td>' + entry.cached_fmt + '</td>';
				html += '<td><span class="acl-ttl-bar ' + ttlClass + '" style="width:' + ttlWidth + 'px"></span>' + formatDuration(entry.ttl_left) + '</td>';
				html += '<td><button type="button" class="button button-small acl-invalidate-entry" data-key="' + entry.route + '">' + aclAdmin.i18n.invalidate + '</button></td>';
				html += '</tr>';
			});

			$browserBody.html(html);

			// Render pagination
			var pagHtml = '';
			if (totalPages > 1) {
				pagHtml += '<div class="acl-browser-pagination">';
				pagHtml += '<span class="acl-browser-pagination-info">' + aclAdmin.i18n.showingEntries.replace('%1$s', (start + 1)).replace('%2$s', end).replace('%3$s', total) + '</span>';
				pagHtml += '<div class="acl-browser-pagination-controls">';
				pagHtml += '<button class="acl-browser-page-btn" data-page="1" ' + (browserPage === 1 ? 'disabled' : '') + '>&laquo;</button>';
				pagHtml += '<button class="acl-browser-page-btn" data-page="' + Math.max(1, browserPage - 1) + '" ' + (browserPage === 1 ? 'disabled' : '') + '>&lsaquo;</button>';

				var startPage = Math.max(1, browserPage - 2);
				var endPage = Math.min(totalPages, startPage + 4);
				for (var p = startPage; p <= endPage; p++) {
					pagHtml += '<button class="acl-browser-page-btn' + (p === browserPage ? ' acl-page-active' : '') + '" data-page="' + p + '">' + p + '</button>';
				}

				pagHtml += '<button class="acl-browser-page-btn" data-page="' + Math.min(totalPages, browserPage + 1) + '" ' + (browserPage === totalPages ? 'disabled' : '') + '>&rsaquo;</button>';
				pagHtml += '<button class="acl-browser-page-btn" data-page="' + totalPages + '" ' + (browserPage === totalPages ? 'disabled' : '') + '>&raquo;</button>';
				pagHtml += '</div></div>';
			}
			$('#acl-browser-pagination').html(pagHtml);
		}

		// =====================================================
		// CACHE WARMER TAB
		// =====================================================
		$('#acl-warm-now').on('click', function () {
			var $btn = $(this);

			guardAction('warm', function (done) {
				btnLoading($btn);
				$('.acl-warmer-progress-fill').css('width', '0%');

				aclAjax('acl_warm_cache')
					.done(function (response) {
						if (response.success) {
							showToast(response.data.message, 'success');
							$('#acl-warmer-state').text(aclAdmin.i18n.completed);
							$('#acl-warmer-progress').text(response.data.success + '/' + response.data.total);
							var pct = response.data.total > 0 ? Math.round((response.data.success / response.data.total) * 100) : 100;
							$('.acl-warmer-progress-fill').css('width', pct + '%');
							$('.acl-warmer-progress-pct').text(pct + '%');
						} else {
							showToast(response.data.message || aclAdmin.i18n.error, 'error');
						}
					})
					.always(function () {
						btnReset($btn);
						done();
					});
			});
		});

		// Save warmer settings
		$('#acl-save-warmer').on('click', function () {
			var $btn = $(this);

			guardAction('save-warmer', function (done) {
				btnLoading($btn);

				aclAjax('acl_save_warmer_settings', {
					enabled: $('#acl-warmer-enabled').is(':checked') ? 1 : 0,
					schedule: $('#acl-warmer-schedule').val(),
					batch_size: $('#acl-warmer-batch').val(),
					max_routes: $('#acl-warmer-max-routes').val(),
					skip_auth: $('#acl-warmer-skip-auth').is(':checked') ? 1 : 0
				})
					.done(function (response) {
						if (response.success) {
							showToast(aclAdmin.i18n.saved, 'success');
						} else {
							showToast(response.data.message || aclAdmin.i18n.error, 'error');
						}
					})
					.always(function () {
						btnReset($btn);
						done();
					});
			});
		});

		// =====================================================
		// REAL-TIME MONITOR TAB
		// =====================================================
		var monitorInterval = null;
		var monitorCountdown = 5;
		var countdownInterval = null;
		var $autoRefresh = $('#acl-monitor-auto-refresh');

		if ($autoRefresh.length) {
			refreshMonitor();

			$autoRefresh.on('change', function () {
				if ($(this).is(':checked')) {
					startMonitorInterval();
					$('.acl-monitor-indicator').show();
				} else {
					stopMonitorInterval();
					$('.acl-monitor-indicator').hide();
				}
			});

			if ($autoRefresh.is(':checked')) {
				startMonitorInterval();
			}

			$('#acl-monitor-refresh').on('click', function () {
				var $btn = $(this);
				btnLoading($btn);
				refreshMonitor(function () {
					btnReset($btn);
				});
				monitorCountdown = parseInt($('#acl-monitor-interval').val(), 10) || 5;
				updateCountdownDisplay();
			});

			// Monitor interval dropdown
			$('#acl-monitor-interval').on('change', function () {
				if ($autoRefresh.is(':checked')) {
					startMonitorInterval();
				}
			});
		}

		function startMonitorInterval() {
			stopMonitorInterval();
			var seconds = parseInt($('#acl-monitor-interval').val(), 10) || 5;
			monitorCountdown = seconds;
			updateCountdownDisplay();

			countdownInterval = setInterval(function () {
				monitorCountdown--;
				if (monitorCountdown <= 0) {
					monitorCountdown = seconds;
					refreshMonitor();
				}
				updateCountdownDisplay();
			}, 1000);
		}

		function stopMonitorInterval() {
			if (monitorInterval) {
				clearInterval(monitorInterval);
				monitorInterval = null;
			}
			if (countdownInterval) {
				clearInterval(countdownInterval);
				countdownInterval = null;
			}
		}

		function updateCountdownDisplay() {
			$('.acl-countdown').text(monitorCountdown + 's');
		}

		function refreshMonitor(callback) {
			aclAjax('acl_get_stats')
				.done(function (response) {
					if (!response.success) return;

					var stats = response.data.stats;
					animateValue($('#acl-monitor-hits'), stats.hits.toLocaleString());
					animateValue($('#acl-monitor-misses'), stats.misses.toLocaleString());
					animateValue($('#acl-monitor-rate'), stats.hit_rate + '%');
					animateValue($('#acl-monitor-cached'), stats.total_cached.toLocaleString());
					animateValue($('#acl-monitor-size'), formatBytes(stats.total_size || 0));

					// Update ring chart if present
					var $ring = $('#acl-monitor-ring-fill');
					if ($ring.length) {
						var circumference = 408.41;
						var offset = circumference - (stats.hit_rate / 100) * circumference;
						$ring.css('stroke-dashoffset', offset);
						$('#acl-monitor-ring-value').text(stats.hit_rate + '%');
					}

					// Invalidation log
					var log = response.data.invalidation_log || [];
					var $logBody = $('#acl-monitor-log-body');

					if (!log.length) {
						$logBody.html('<tr><td colspan="5" style="text-align:center;padding:16px;color:var(--acl-text-muted);">' + aclAdmin.i18n.noData + '</td></tr>');
						return;
					}

					var html = '';
					log.forEach(function (entry) {
						html += '<tr>';
						html += '<td>' + entry.created_at + '</td>';
						html += '<td class="acl-route-cell">' + entry.route + '</td>';
						html += '<td>' + entry.reason + '</td>';
						html += '<td>' + entry.source + '</td>';
						html += '<td>' + entry.entries_cleared + '</td>';
						html += '</tr>';
					});

					$logBody.html(html);
				})
				.always(function () {
					if (typeof callback === 'function') callback();
				});
		}

		// Clean up intervals when leaving the page
		$(window).on('beforeunload', function () {
			stopMonitorInterval();
			stopAnalyticsAutoRefresh();
		});
	});
})(jQuery);
