/**
 * WODO Bridge — admin UI module.
 *
 * Vanilla JS, no React, no jQuery. Uses wp.apiFetch + wp.i18n.
 * Loaded only on Settings → WODO Bridge.
 */
(() => {
	'use strict';

	const cfg = window.wodoBridgeAdmin || {};
	if (!cfg.restRoot || !window.wp || !window.wp.apiFetch || !window.wp.i18n) {
		return;
	}

	const apiFetch = window.wp.apiFetch;
	const __ = window.wp.i18n.__;
	const sprintf = window.wp.i18n.sprintf || ((fmt, ...args) => {
		// Minimal fallback: handles %s, %d, and positional %1$s / %2$d.
		let i = 0;
		return String(fmt).replace(/%(?:(\d+)\$)?[sd]/g, (_m, pos) => {
			const idx = pos ? parseInt(pos, 10) - 1 : i++;
			return String(args[idx] !== undefined ? args[idx] : '');
		});
	});

	apiFetch.use(apiFetch.createNonceMiddleware(cfg.nonce));
	apiFetch.use(apiFetch.createRootURLMiddleware(cfg.restRootGlobal || cfg.restRoot));

	// ----- Utilities -----------------------------------------------------------

	const el = (tag, attrs = {}, children = []) => {
		const node = document.createElement(tag);
		for (const [k, v] of Object.entries(attrs)) {
			if (v == null) continue;
			if (k === 'class') node.className = v;
			else if (k === 'dataset') Object.assign(node.dataset, v);
			else if (k.startsWith('on') && typeof v === 'function') node.addEventListener(k.slice(2), v);
			else if (k === 'text') node.textContent = String(v);
			else node.setAttribute(k, String(v));
		}
		for (const child of [].concat(children)) {
			if (child == null) continue;
			node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
		}
		return node;
	};

	const fmtDate = (iso) => {
		if (!iso) return '—';
		try {
			// Activity rows are stored as MySQL UTC strings (no Z); coerce.
			const safe = iso.includes('T') ? iso : iso.replace(' ', 'T') + 'Z';
			const d = new Date(safe);
			if (Number.isNaN(d.getTime())) return iso;
			return d.toLocaleString();
		} catch (_) {
			return iso;
		}
	};

	const fmtRelative = (iso) => {
		if (!iso) return __('Never', 'wodo-bridge');
		return fmtDate(iso);
	};

	const apiPath = (suffix, query = null) => {
		// suffix begins without leading slash, e.g. "auth/tokens".
		const base = `/${cfg.restNamespace}/${suffix}`;
		if (!query) return base;
		const params = new URLSearchParams();
		for (const [k, v] of Object.entries(query)) {
			if (v === '' || v == null) continue;
			params.append(k, String(v));
		}
		const qs = params.toString();
		return qs ? `${base}?${qs}` : base;
	};

	const api = (path, options = {}) => apiFetch({ path, ...options });

	const showNotice = (type, message, traceId) => {
		const host = document.getElementById('wodo-bridge-notices');
		if (!host) return;
		const text = traceId ? `${message} (trace: ${traceId})` : message;
		const cls = `notice notice-${type === 'error' ? 'error' : type === 'success' ? 'success' : 'info'} is-dismissible wodo-bridge-notice`;
		const note = el('div', { class: cls, role: 'status' }, [
			el('p', { text }),
			el('button', {
				type: 'button',
				class: 'notice-dismiss',
				'aria-label': __('Dismiss notice', 'wodo-bridge'),
				onclick: () => note.remove(),
			}, [el('span', { class: 'screen-reader-text', text: __('Dismiss notice', 'wodo-bridge') })]),
		]);
		host.appendChild(note);
		if (type === 'success') {
			setTimeout(() => note.remove(), 5000);
		}
	};

	const handleApiError = (err, fallbackMsg) => {
		// wp.apiFetch rejects with the parsed response body.
		const envelope = err && err.error ? err.error : null;
		const message = (envelope && envelope.message) || (err && err.message) || fallbackMsg || __('Request failed.', 'wodo-bridge');
		const traceId = envelope && envelope.trace_id;
		showNotice('error', message, traceId);
	};

	const confirmDialog = (title, body) => new Promise((resolve) => {
		const dlg = document.getElementById('wodo-bridge-confirm-dialog');
		if (!dlg || !dlg.showModal) {
			resolve(false);
			return;
		}
		document.getElementById('wodo-bridge-confirm-title').textContent = title;
		document.getElementById('wodo-bridge-confirm-body').textContent = body;

		const cancelBtn = dlg.querySelector('[data-wodo-dialog-cancel]');
		const confirmBtn = dlg.querySelector('[data-wodo-dialog-confirm]');

		const cleanup = (result) => {
			cancelBtn.removeEventListener('click', onCancel);
			confirmBtn.removeEventListener('click', onConfirm);
			dlg.close();
			resolve(result);
		};
		const onCancel = () => cleanup(false);
		const onConfirm = () => cleanup(true);
		cancelBtn.addEventListener('click', onCancel);
		confirmBtn.addEventListener('click', onConfirm);
		dlg.showModal();
	});

	const wireDialogCancel = (dialog) => {
		dialog.querySelectorAll('[data-wodo-dialog-cancel]').forEach((btn) => {
			btn.addEventListener('click', () => dialog.close());
		});
	};

	const copyToClipboard = async (text) => {
		try {
			await navigator.clipboard.writeText(text);
			return true;
		} catch (_) {
			return false;
		}
	};

	const renderPagination = (host, state, onPage) => {
		host.innerHTML = '';
		const { page, totalPages, total } = state;
		if (totalPages <= 1) {
			if (total > 0) {
				host.appendChild(el('span', {
					class: 'wodo-bridge-pagination__count',
					text: sprintf(__('%d total', 'wodo-bridge'), total),
				}));
			}
			return;
		}

		const make = (label, target, opts = {}) => el('button', {
			type: 'button',
			class: 'button wodo-bridge-page-btn' + (opts.active ? ' is-active' : ''),
			disabled: opts.disabled ? 'disabled' : null,
			onclick: () => onPage(target),
			text: label,
		});

		host.appendChild(make('«', 1, { disabled: page <= 1 }));
		host.appendChild(make('‹', page - 1, { disabled: page <= 1 }));

		const start = Math.max(1, page - 2);
		const end = Math.min(totalPages, page + 2);
		for (let i = start; i <= end; i++) {
			host.appendChild(make(String(i), i, { active: i === page }));
		}

		host.appendChild(make('›', page + 1, { disabled: page >= totalPages }));
		host.appendChild(make('»', totalPages, { disabled: page >= totalPages }));

		host.appendChild(el('span', {
			class: 'wodo-bridge-pagination__count',
			text: sprintf(__('Page %1$d of %2$d (%3$d total)', 'wodo-bridge'), page, totalPages, total),
		}));
	};

	const setEmptyRow = (tbody, colspan, message) => {
		tbody.innerHTML = '';
		tbody.appendChild(el('tr', { class: 'wodo-bridge-empty-row' }, [
			el('td', { colspan: String(colspan) }, [
				el('div', { class: 'wodo-bridge-empty', text: message }),
			]),
		]));
	};

	const setSkeletonRows = (tbody, colspan, count = 3) => {
		tbody.innerHTML = '';
		for (let i = 0; i < count; i++) {
			tbody.appendChild(el('tr', { class: 'wodo-bridge-skeleton-row' }, [
				el('td', { colspan: String(colspan) }, [
					el('div', { class: 'wodo-bridge-skeleton' }),
				]),
			]));
		}
	};

	const statusPill = (httpStatus) => {
		const tone = httpStatus >= 500 ? 'error'
			: httpStatus >= 400 ? 'warn'
			: httpStatus >= 200 ? 'ok'
			: 'muted';
		return el('span', {
			class: `wodo-bridge-pill wodo-bridge-pill--${tone}`,
			text: String(httpStatus || '—'),
		});
	};

	// Tiny inline JSON syntax highlighter — no external dep.
	const highlightJson = (value) => {
		let json;
		try {
			json = typeof value === 'string' ? JSON.parse(value) : value;
		} catch (_) {
			json = value;
		}
		const text = JSON.stringify(json, null, 2) || String(value);
		const escaped = text
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;');
		const html = escaped.replace(
			/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+-]?\d+)?)/g,
			(match) => {
				let cls = 'wodo-jh-num';
				if (/^"/.test(match)) {
					cls = /:$/.test(match) ? 'wodo-jh-key' : 'wodo-jh-str';
				} else if (/true|false/.test(match)) {
					cls = 'wodo-jh-bool';
				} else if (/null/.test(match)) {
					cls = 'wodo-jh-null';
				}
				return `<span class="${cls}">${match}</span>`;
			}
		);
		const pre = document.createElement('pre');
		pre.className = 'wodo-bridge-json';
		// JSON has been HTML-escaped above; only our own span markup is reinjected.
		pre.innerHTML = html;
		return pre;
	};

	// ----- Connections tab -----------------------------------------------------

	const Connections = {
		state: {
			page: 1,
			perPage: 25,
			status: 'active',
			scope: '',
			totalPages: 1,
			total: 0,
			loading: false,
		},

		init() {
			const tbody = document.getElementById('wodo-bridge-tokens-body');
			if (!tbody) return;

			this.populateAuthorizeUrl();
			this.bindCopyAuthorize();
			this.bindFilters();
			this.fetch();
		},

		populateAuthorizeUrl() {
			const input = document.getElementById('wodo-bridge-authorize-url');
			if (!input) return;
			const aggregator = (cfg.aggregatorUrl || '').replace(/\/+$/, '');
			const successUrl = aggregator ? `${aggregator}/connect/callback` : '';
			const rejectUrl = aggregator ? `${aggregator}/connect/cancel` : '';
			const params = new URLSearchParams({
				app_name: 'WODO Bridge',
				app_id: cfg.pluginUuid || '',
				success_url: successUrl,
				reject_url: rejectUrl,
			});
			const url = `${(cfg.siteUrl || '').replace(/\/+$/, '')}/wp-admin/authorize-application.php?${params.toString()}`;
			input.value = url;
		},

		bindCopyAuthorize() {
			const btn = document.querySelector('[data-wodo-action="copy-authorize"]');
			if (!btn) return;
			btn.addEventListener('click', async () => {
				const input = document.getElementById('wodo-bridge-authorize-url');
				if (!input || !input.value) {
					showNotice('error', __('Enter an Aggregator URL above first.', 'wodo-bridge'));
					return;
				}
				const ok = await copyToClipboard(input.value);
				if (ok) {
					showNotice('success', __('Authorize URL copied to clipboard.', 'wodo-bridge'));
				} else {
					input.select();
					showNotice('error', __('Could not copy. The URL is selected — copy manually.', 'wodo-bridge'));
				}
			});
		},

		bindFilters() {
			const status = document.getElementById('wodo-bridge-token-status');
			const scope = document.getElementById('wodo-bridge-token-scope');
			if (status) {
				status.addEventListener('change', () => {
					this.state.status = status.value;
					this.state.page = 1;
					this.fetch();
				});
			}
			if (scope) {
				scope.addEventListener('change', () => {
					this.state.scope = scope.value;
					this.state.page = 1;
					this.fetch();
				});
			}
		},

		async fetch() {
			const tbody = document.getElementById('wodo-bridge-tokens-body');
			if (!tbody || this.state.loading) return;
			this.state.loading = true;
			setSkeletonRows(tbody, 7, 3);

			try {
				const data = await api(apiPath('auth/tokens', {
					page: this.state.page,
					per_page: this.state.perPage,
					status: this.state.status,
					scope: this.state.scope,
				}));
				this.state.total = data.total || 0;
				this.state.totalPages = data.total_pages || 1;
				this.render(data.tokens || []);
			} catch (err) {
				handleApiError(err, __('Failed to load tokens.', 'wodo-bridge'));
				setEmptyRow(tbody, 7, __('Could not load tokens.', 'wodo-bridge'));
			} finally {
				this.state.loading = false;
			}
		},

		render(rows) {
			const tbody = document.getElementById('wodo-bridge-tokens-body');
			if (!tbody) return;

			if (rows.length === 0) {
				setEmptyRow(tbody, 7, __('No connections yet. Add an Aggregator URL above and use the connect button on your aggregator dashboard to link this site.', 'wodo-bridge'));
			} else {
				tbody.innerHTML = '';
				for (const row of rows) {
					tbody.appendChild(this.row(row));
				}
			}

			const pag = document.getElementById('wodo-bridge-tokens-pagination');
			if (pag) {
				renderPagination(pag, this.state, (p) => {
					this.state.page = p;
					this.fetch();
				});
			}
		},

		row(token) {
			const scopesCell = el('td', { class: 'wodo-bridge-scopes' });
			(token.scopes || []).forEach((scope) => {
				scopesCell.appendChild(el('span', {
					class: 'wodo-bridge-pill wodo-bridge-pill--scope',
					text: scope,
				}));
			});
			if (!token.scopes || token.scopes.length === 0) {
				scopesCell.appendChild(document.createTextNode('—'));
			}

			const userInfo = token.wp_user || {};
			const userLabel = userInfo.display_name || userInfo.login || `#${token.wp_user_id}`;

			const statusCell = el('td');
			if (token.revoked_at) {
				statusCell.appendChild(el('span', {
					class: 'wodo-bridge-pill wodo-bridge-pill--error',
					text: __('Revoked', 'wodo-bridge'),
				}));
			} else {
				statusCell.appendChild(el('span', {
					class: 'wodo-bridge-pill wodo-bridge-pill--ok',
					text: __('Active', 'wodo-bridge'),
				}));
			}

			const actionsCell = el('td', { class: 'wodo-bridge-col-actions' });
			if (!token.revoked_at) {
				const btn = el('button', {
					type: 'button',
					class: 'button button-link-delete',
					text: __('Revoke', 'wodo-bridge'),
				});
				btn.addEventListener('click', () => this.confirmRevoke(token));
				actionsCell.appendChild(btn);
			} else {
				actionsCell.appendChild(document.createTextNode('—'));
			}

			return el('tr', {}, [
				el('td', { class: 'wodo-bridge-cell-label' }, [
					el('strong', { text: token.label || `#${token.id}` }),
				]),
				el('td', { text: userLabel }),
				scopesCell,
				el('td', { text: fmtDate(token.created_at) }),
				el('td', { text: fmtRelative(token.last_used_at) }),
				statusCell,
				actionsCell,
			]);
		},

		async confirmRevoke(token) {
			const ok = await confirmDialog(
				__('Revoke this token?', 'wodo-bridge'),
				sprintf(__('This will immediately disable %s. Existing aggregators using this token will stop working.', 'wodo-bridge'), token.label || `#${token.id}`)
			);
			if (!ok) return;

			try {
				await api(apiPath(`auth/tokens/${token.id}`), { method: 'DELETE' });
				showNotice('success', __('Token revoked.', 'wodo-bridge'));
				this.fetch();
			} catch (err) {
				handleApiError(err, __('Failed to revoke token.', 'wodo-bridge'));
			}
		},
	};

	// ----- Webhooks tab --------------------------------------------------------

	const Webhooks = {
		state: { loading: false, items: [] },

		init() {
			if (!document.getElementById('wodo-bridge-webhooks-body')) return;
			this.bindAdd();
			this.bindCopySecret();
			this.fetch();

			['wodo-bridge-webhook-add-dialog', 'wodo-bridge-webhook-secret-dialog', 'wodo-bridge-webhook-deliveries-dialog'].forEach((id) => {
				const dlg = document.getElementById(id);
				if (dlg) wireDialogCancel(dlg);
			});

			const form = document.getElementById('wodo-bridge-webhook-add-form');
			if (form) {
				form.addEventListener('submit', (ev) => {
					ev.preventDefault();
					this.handleCreate(form);
				});
			}
		},

		bindAdd() {
			const btn = document.querySelector('[data-wodo-action="open-add-webhook"]');
			if (!btn) return;
			btn.addEventListener('click', () => {
				const dlg = document.getElementById('wodo-bridge-webhook-add-dialog');
				if (dlg && dlg.showModal) dlg.showModal();
			});
		},

		bindCopySecret() {
			const btn = document.querySelector('[data-wodo-action="copy-secret"]');
			if (!btn) return;
			btn.addEventListener('click', async () => {
				const input = document.getElementById('wodo-bridge-webhook-secret-value');
				if (!input || !input.value) return;
				const ok = await copyToClipboard(input.value);
				if (ok) showNotice('success', __('Secret copied to clipboard.', 'wodo-bridge'));
			});
		},

		async fetch() {
			const tbody = document.getElementById('wodo-bridge-webhooks-body');
			if (!tbody) return;
			this.state.loading = true;
			setSkeletonRows(tbody, 5, 2);
			try {
				const data = await api(apiPath('webhooks'));
				this.state.items = (data && data.webhooks) || [];
				this.render();
			} catch (err) {
				handleApiError(err, __('Failed to load webhooks.', 'wodo-bridge'));
				setEmptyRow(tbody, 5, __('Could not load webhooks.', 'wodo-bridge'));
			} finally {
				this.state.loading = false;
			}
		},

		render() {
			const tbody = document.getElementById('wodo-bridge-webhooks-body');
			if (!tbody) return;
			const items = this.state.items;
			if (!items.length) {
				setEmptyRow(tbody, 5, __('No webhooks configured. Click "Add webhook" to register an endpoint.', 'wodo-bridge'));
				return;
			}
			tbody.innerHTML = '';
			items.forEach((hook) => tbody.appendChild(this.row(hook)));
		},

		row(hook) {
			const eventsCell = el('td', { class: 'wodo-bridge-events' });
			(hook.events || []).forEach((evt) => {
				eventsCell.appendChild(el('span', {
					class: 'wodo-bridge-pill wodo-bridge-pill--scope',
					text: evt,
				}));
			});

			const statusCell = el('td');
			statusCell.appendChild(el('span', {
				class: 'wodo-bridge-pill wodo-bridge-pill--' + (hook.active ? 'ok' : 'muted'),
				text: hook.active ? __('Active', 'wodo-bridge') : __('Disabled', 'wodo-bridge'),
			}));

			const actionsCell = el('td', { class: 'wodo-bridge-col-actions' });
			const test = el('button', {
				type: 'button',
				class: 'button',
				text: __('Test', 'wodo-bridge'),
			});
			test.addEventListener('click', () => this.test(hook));

			const toggle = el('button', {
				type: 'button',
				class: 'button',
				text: hook.active ? __('Disable', 'wodo-bridge') : __('Enable', 'wodo-bridge'),
			});
			toggle.addEventListener('click', () => this.toggle(hook));

			const del = el('button', {
				type: 'button',
				class: 'button button-link-delete',
				text: __('Delete', 'wodo-bridge'),
			});
			del.addEventListener('click', () => this.remove(hook));

			actionsCell.appendChild(test);
			actionsCell.appendChild(toggle);
			actionsCell.appendChild(del);

			const targetCell = el('td', { class: 'wodo-bridge-cell-target' });
			const link = el('a', { href: '#', text: hook.target_url, class: 'wodo-bridge-link' });
			link.addEventListener('click', (ev) => {
				ev.preventDefault();
				this.openDeliveries(hook);
			});
			targetCell.appendChild(link);

			return el('tr', {}, [
				targetCell,
				eventsCell,
				el('td', { text: fmtRelative(hook.last_delivery_at) }),
				statusCell,
				actionsCell,
			]);
		},

		async handleCreate(form) {
			const fd = new FormData(form);
			const target = (fd.get('target_url') || '').toString().trim();
			const events = fd.getAll('events').map((v) => String(v));

			if (!/^https:\/\//i.test(target)) {
				showNotice('error', __('Target URL must use HTTPS.', 'wodo-bridge'));
				return;
			}
			if (events.length === 0) {
				showNotice('error', __('Select at least one event.', 'wodo-bridge'));
				return;
			}

			try {
				const created = await api(apiPath('webhooks'), {
					method: 'POST',
					data: { target_url: target, events, active: true },
				});
				const dlg = document.getElementById('wodo-bridge-webhook-add-dialog');
				if (dlg) dlg.close();
				form.reset();

				const secret = created && created.secret;
				if (secret) {
					const sdlg = document.getElementById('wodo-bridge-webhook-secret-dialog');
					const sval = document.getElementById('wodo-bridge-webhook-secret-value');
					if (sval) sval.value = secret;
					if (sdlg && sdlg.showModal) sdlg.showModal();
				} else {
					showNotice('success', __('Webhook created.', 'wodo-bridge'));
				}

				this.fetch();
			} catch (err) {
				handleApiError(err, __('Failed to create webhook.', 'wodo-bridge'));
			}
		},

		async test(hook) {
			try {
				const result = await api(apiPath(`webhooks/${hook.id}/test`), {
					method: 'POST',
					data: { event: 'webhook.test', payload: { test: true } },
				});
				const code = result && (result.response_code || result.http_status);
				const latency = result && (result.latency_ms || 0);
				const ok = result && result.status === 'ok';
				const tone = ok ? 'success' : 'error';
				showNotice(
					tone,
					sprintf(__('Test fired. HTTP %1$s in %2$dms.', 'wodo-bridge'), String(code || '—'), Number(latency || 0))
				);
			} catch (err) {
				handleApiError(err, __('Test delivery failed.', 'wodo-bridge'));
			}
		},

		async toggle(hook) {
			try {
				await api(apiPath(`webhooks/${hook.id}`), {
					method: 'PUT',
					data: { active: !hook.active },
				});
				showNotice('success', hook.active ? __('Webhook disabled.', 'wodo-bridge') : __('Webhook enabled.', 'wodo-bridge'));
				this.fetch();
			} catch (err) {
				handleApiError(err, __('Could not update webhook.', 'wodo-bridge'));
			}
		},

		async remove(hook) {
			const ok = await confirmDialog(
				__('Delete this webhook?', 'wodo-bridge'),
				sprintf(__('This permanently removes %s. This cannot be undone.', 'wodo-bridge'), hook.target_url)
			);
			if (!ok) return;
			try {
				await api(apiPath(`webhooks/${hook.id}`), { method: 'DELETE' });
				showNotice('success', __('Webhook deleted.', 'wodo-bridge'));
				this.fetch();
			} catch (err) {
				handleApiError(err, __('Failed to delete webhook.', 'wodo-bridge'));
			}
		},

		async openDeliveries(hook) {
			const dlg = document.getElementById('wodo-bridge-webhook-deliveries-dialog');
			const body = document.getElementById('wodo-bridge-deliveries-body');
			if (!dlg || !body) return;
			body.innerHTML = '';
			body.appendChild(el('div', { class: 'wodo-bridge-skeleton' }));
			body.appendChild(el('div', { class: 'wodo-bridge-skeleton' }));
			if (dlg.showModal) dlg.showModal();

			try {
				const data = await api(apiPath(`webhooks/${hook.id}/deliveries`, { per_page: 50 }));
				const rows = (data && data.deliveries) || [];
				body.innerHTML = '';
				if (!rows.length) {
					body.appendChild(el('p', { class: 'wodo-bridge-empty', text: __('No deliveries yet.', 'wodo-bridge') }));
					return;
				}
				const table = el('table', { class: 'wp-list-table widefat fixed striped wodo-bridge-table' });
				const thead = el('thead', {}, [el('tr', {}, [
					el('th', { text: __('When', 'wodo-bridge') }),
					el('th', { text: __('Event', 'wodo-bridge') }),
					el('th', { text: __('HTTP', 'wodo-bridge') }),
					el('th', { text: __('Attempt', 'wodo-bridge') }),
					el('th', { text: __('Latency', 'wodo-bridge') }),
					el('th', { text: __('Response excerpt', 'wodo-bridge') }),
				])]);
				const tbody = el('tbody');
				rows.forEach((d) => {
					tbody.appendChild(el('tr', {}, [
						el('td', { text: fmtDate(d.delivered_at) }),
						el('td', { text: d.event || '—' }),
						el('td', {}, [statusPill(Number(d.http_status))]),
						el('td', { text: String(d.attempt || 1) }),
						el('td', { text: `${Number(d.latency_ms || 0)}ms` }),
						el('td', { class: 'wodo-bridge-cell-excerpt', text: d.response_excerpt || '' }),
					]));
				});
				table.appendChild(thead);
				table.appendChild(tbody);
				body.appendChild(table);
			} catch (err) {
				body.innerHTML = '';
				handleApiError(err, __('Could not load deliveries.', 'wodo-bridge'));
				body.appendChild(el('p', { class: 'wodo-bridge-empty', text: __('Could not load deliveries.', 'wodo-bridge') }));
			}
		},
	};

	// ----- Activity tab --------------------------------------------------------

	const Activity = {
		state: {
			page: 1,
			perPage: 25,
			totalPages: 1,
			total: 0,
			tokenId: '',
			endpoint: '',
			status: '',
			from: '',
			to: '',
			loading: false,
		},

		init() {
			if (!document.getElementById('wodo-bridge-activity-body')) return;
			this.bindFilters();
			this.bindExport();
			this.loadDistinct().then(() => this.fetch());
		},

		bindFilters() {
			const apply = document.querySelector('[data-wodo-action="apply-activity-filters"]');
			if (apply) apply.addEventListener('click', () => {
				this.collectFilters();
				this.state.page = 1;
				this.fetch();
			});
		},

		bindExport() {
			const link = document.getElementById('wodo-bridge-export-activity');
			if (!link) return;
			link.addEventListener('click', (ev) => {
				ev.preventDefault();
				this.collectFilters();
				const params = new URLSearchParams();
				params.set('_wpnonce', cfg.nonce);
				if (this.state.tokenId) params.set('token_id', this.state.tokenId);
				if (this.state.endpoint) params.set('endpoint', this.state.endpoint);
				if (this.state.status) params.set('status', this.state.status);
				if (this.state.from) params.set('from', this.state.from);
				if (this.state.to) params.set('to', this.state.to);
				const url = `${cfg.restRoot}activity/export.csv?${params.toString()}`;
				window.location.href = url;
			});
		},

		collectFilters() {
			this.state.tokenId = (document.getElementById('wodo-bridge-activity-token') || {}).value || '';
			this.state.endpoint = (document.getElementById('wodo-bridge-activity-endpoint') || {}).value || '';
			this.state.status = (document.getElementById('wodo-bridge-activity-status') || {}).value || '';
			this.state.from = (document.getElementById('wodo-bridge-activity-from') || {}).value || '';
			this.state.to = (document.getElementById('wodo-bridge-activity-to') || {}).value || '';
		},

		async loadDistinct() {
			try {
				const data = await api(apiPath('activity/distinct'));
				const tokenSelect = document.getElementById('wodo-bridge-activity-token');
				const endpointSelect = document.getElementById('wodo-bridge-activity-endpoint');
				if (tokenSelect && Array.isArray(data.tokens)) {
					data.tokens.forEach((t) => {
						tokenSelect.appendChild(el('option', {
							value: String(t.id),
							text: (t.label || `#${t.id}`) + (t.active ? '' : ' (revoked)'),
						}));
					});
				}
				if (endpointSelect && Array.isArray(data.endpoints)) {
					data.endpoints.forEach((endpoint) => {
						endpointSelect.appendChild(el('option', { value: endpoint, text: endpoint }));
					});
				}
			} catch (_) {
				// Silently degrade — filters remain usable as free text-like dropdowns.
			}
		},

		async fetch() {
			const tbody = document.getElementById('wodo-bridge-activity-body');
			if (!tbody || this.state.loading) return;
			this.state.loading = true;
			setSkeletonRows(tbody, 7, 4);

			try {
				const data = await api(apiPath('activity', {
					page: this.state.page,
					per_page: this.state.perPage,
					token_id: this.state.tokenId,
					endpoint: this.state.endpoint,
					status: this.state.status,
					from: this.state.from,
					to: this.state.to,
				}));
				this.state.total = data.total || 0;
				this.state.totalPages = data.total_pages || 1;
				this.render(data.activity || []);
			} catch (err) {
				handleApiError(err, __('Failed to load activity.', 'wodo-bridge'));
				setEmptyRow(tbody, 7, __('Could not load activity.', 'wodo-bridge'));
			} finally {
				this.state.loading = false;
			}
		},

		render(rows) {
			const tbody = document.getElementById('wodo-bridge-activity-body');
			if (!tbody) return;
			if (!rows.length) {
				setEmptyRow(tbody, 7, __('No activity matches your filters.', 'wodo-bridge'));
			} else {
				tbody.innerHTML = '';
				rows.forEach((row) => tbody.appendChild(this.row(row)));
			}
			const pag = document.getElementById('wodo-bridge-activity-pagination');
			if (pag) {
				renderPagination(pag, this.state, (p) => {
					this.state.page = p;
					this.fetch();
				});
			}
		},

		row(entry) {
			const ipCell = el('td', { class: 'wodo-bridge-cell-ip' });
			ipCell.appendChild(document.createTextNode(entry.ip || '—'));
			if (entry.proxy_trusted) {
				ipCell.appendChild(document.createTextNode(' '));
				ipCell.appendChild(el('span', {
					class: 'wodo-bridge-pill wodo-bridge-pill--muted',
					text: __('proxy', 'wodo-bridge'),
					title: __('IP came from a trusted proxy header.', 'wodo-bridge'),
				}));
			}

			const tokenLabel = entry.token_label || (entry.token_id ? `#${entry.token_id}` : '—');

			const actionCell = el('td', { class: 'wodo-bridge-cell-action' }, [
				el('code', { text: `${entry.method || ''} ${entry.endpoint || ''}` }),
			]);

			const tr = el('tr', { class: 'wodo-bridge-row-clickable' }, [
				el('td', { text: fmtDate(entry.created_at) }),
				actionCell,
				el('td', { text: tokenLabel }),
				el('td', {}, [statusPill(Number(entry.http_status))]),
				el('td', { text: `${Number(entry.latency_ms || 0)}ms` }),
				ipCell,
				el('td', { class: 'wodo-bridge-cell-trace' }, [
					el('code', { text: entry.error_code || '—' }),
				]),
			]);

			tr.addEventListener('click', () => this.toggleDetails(tr, entry));
			return tr;
		},

		toggleDetails(row, entry) {
			const next = row.nextElementSibling;
			if (next && next.classList.contains('wodo-bridge-row-details')) {
				next.remove();
				return;
			}

			const meta = {
				request: {
					endpoint: entry.endpoint,
					method: entry.method,
					user_agent: entry.user_agent,
					ip: entry.ip,
					proxy_trusted: entry.proxy_trusted,
				},
				response: {
					http_status: entry.http_status,
					error_code: entry.error_code,
					latency_ms: entry.latency_ms,
					timestamp_utc: entry.created_at,
				},
			};

			const detailsRow = el('tr', { class: 'wodo-bridge-row-details' }, [
				el('td', { colspan: '7' }, [
					el('div', { class: 'wodo-bridge-details' }, [
						el('strong', { text: __('Request', 'wodo-bridge') }),
						highlightJson(meta.request),
						el('strong', { text: __('Response', 'wodo-bridge') }),
						highlightJson(meta.response),
					]),
				]),
			]);

			row.parentNode.insertBefore(detailsRow, row.nextSibling);
		},
	};

	// ----- Bootstrap -----------------------------------------------------------

	const onReady = (fn) => {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', fn);
		} else {
			fn();
		}
	};

	onReady(() => {
		const panel = document.querySelector('.wodo-bridge-admin__panel');
		if (!panel) return;
		const tab = panel.dataset.activeTab || cfg.currentScreen || 'connections';
		if (tab === 'connections') Connections.init();
		else if (tab === 'webhooks') Webhooks.init();
		else if (tab === 'activity') Activity.init();
	});
})();
