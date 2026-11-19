/**
 * PrivateBin
 *
 * a zero-knowledge paste bin
 *
 * @link      https://github.com/PrivateBin/PrivateBin
 * @copyright 2012 Sébastien SAUVAGE (sebsauvage.net)
 * @license   https://www.opensource.org/licenses/zlib-license.php The zlib/libpng License
 */
'use strict';

(function () {
	const csrf = document.body.dataset.csrf || '';
	let i18n = {};
	try {
		i18n = JSON.parse(document.body.dataset.i18n || '{}');
	} catch (error) {
		i18n = {};
	}

	/**
	 * get an element by ID
	 *
	 * @param {string} id
	 * @return {HTMLElement|null}
	 */
	function byId(id) {
		return document.getElementById(id);
	}

	/**
	 * translate a string with a fallback
	 *
	 * @param {string} text
	 * @return {string}
	 */
	function t(text) {
		return i18n[text] || text;
	}

	/**
	 * show a message in one of the status alerts
	 *
	 * @param {string} id
	 * @param {string} message
	 */
	function showMessage(id, message) {
		const element = byId(id);
		if (!element) {
			return;
		}
		element.textContent = message;
		element.classList.remove('d-none');
	}

	/**
	 * hide a status alert
	 *
	 * @param {string} id
	 */
	function hideMessage(id) {
		const element = byId(id);
		if (element) {
			element.classList.add('d-none');
		}
	}

	/**
	 * call the administration API
	 *
	 * @param {string} action
	 * @param {object} parameters
	 * @param {string} method
	 * @return {Promise<object>}
	 */
	async function api(action, parameters, method) {
		const options = {
			method: method || 'GET',
			credentials: 'same-origin',
			headers: {
				'Accept': 'application/json'
			}
		};
		let url = 'admin.php?action=' + encodeURIComponent(action);
		if (options.method === 'GET' && parameters) {
			url += '&' + new URLSearchParams(parameters).toString();
		} else if (parameters) {
			options.headers['Content-Type'] = 'application/json';
			options.headers['X-CSRF-Token'] = csrf;
			options.body = JSON.stringify(parameters);
		}
		const response = await fetch(url, options);
		let data;
		try {
			data = await response.json();
		} catch (error) {
			throw new Error(t('The server returned an invalid response.'));
		}
		if (!response.ok || data.status !== 0) {
			throw new Error(data.message || t('Request failed.'));
		}
		return data;
	}

	/**
	 * format a unix timestamp as a local date
	 *
	 * @param {number|null} timestamp
	 * @return {string}
	 */
	function formatDate(timestamp) {
		if (!timestamp) {
			return '—';
		}
		return new Date(timestamp * 1000).toLocaleString();
	}

	/**
	 * format a byte size
	 *
	 * @param {number} bytes
	 * @return {string}
	 */
	function formatSize(bytes) {
		if (bytes < 1024) {
			return bytes + ' B';
		}
		if (bytes < 1048576) {
			return (bytes / 1024).toFixed(1) + ' KiB';
		}
		return (bytes / 1048576).toFixed(1) + ' MiB';
	}

	/**
	 * create a table cell
	 *
	 * @param {string} text
	 * @param {string} className
	 * @return {HTMLTableCellElement}
	 */
	function cell(text, className) {
		const element = document.createElement('td');
		element.textContent = text;
		if (className) {
			element.className = className;
		}
		return element;
	}

	/**
	 * create a button used for a table row action
	 *
	 * @param {string} label
	 * @param {string} className
	 * @param {function} handler
	 * @return {HTMLButtonElement}
	 */
	function actionButton(label, className, handler) {
		const button = document.createElement('button');
		button.type = 'button';
		button.className = 'btn btn-sm ' + className;
		button.textContent = label;
		button.addEventListener('click', handler);
		return button;
	}

	/**
	 * login form handling
	 */
	function setupLogin() {
		const form = byId('loginform');
		if (!form) {
			return;
		}
		form.addEventListener('submit', async function (event) {
			event.preventDefault();
			hideMessage('loginerror');
			try {
				await api('login', { token: byId('token').value }, 'POST');
				window.location.reload();
			} catch (error) {
				showMessage('loginerror', error.message);
			}
		});
	}

	/**
	 * administration interface state
	 */
	const state = {
		page: 1,
		pages: 1
	};

	/**
	 * read the current filter values
	 *
	 * @return {object}
	 */
	function filters() {
		return {
			q: byId('q').value,
			tag: byId('tag').value,
			from: byId('from').value,
			to: byId('to').value,
			status: byId('status').value,
			sort: byId('sort').value,
			perpage: byId('perpage').value,
			page: state.page
		};
	}

	/**
	 * update the tag filter options
	 *
	 * @param {array} tags
	 */
	function updateTags(tags) {
		const select = byId('tag');
		const selected = select.value;
		select.textContent = '';
		const all = document.createElement('option');
		all.value = '';
		all.textContent = t('All');
		select.appendChild(all);
		tags.forEach(function (tag) {
			const option = document.createElement('option');
			option.value = tag;
			option.textContent = tag;
			select.appendChild(option);
		});
		select.value = tags.indexOf(selected) !== -1 ? selected : '';
	}

	/**
	 * run an action and reload the list on success
	 *
	 * @param {string} action
	 * @param {object} parameters
	 * @param {string} successMessage
	 */
	async function runAction(action, parameters, successMessage) {
		hideMessage('adminerror');
		hideMessage('adminstatus');
		try {
			await api(action, parameters, 'POST');
			showMessage('adminstatus', successMessage);
			await loadPastes();
		} catch (error) {
			showMessage('adminerror', error.message);
		}
	}

	/**
	 * render the paste table
	 *
	 * @param {array} pastes
	 */
	function renderPastes(pastes) {
		const body = byId('pastes');
		body.textContent = '';
		pastes.forEach(function (paste) {
			const row = document.createElement('tr');

			const nameCell = document.createElement('td');
			// not a link: the administration interface has no key, so opening the
			// document would only show the missing key error
			const name = document.createElement('span');
			name.className = 'paste-name';
			name.textContent = paste.name || paste.id;
			nameCell.appendChild(name);
			if (paste.name && paste.name !== paste.id) {
				const id = document.createElement('div');
				id.className = 'paste-id';
				id.textContent = paste.id;
				nameCell.appendChild(id);
			}
			if (paste.expired) {
				const badge = document.createElement('span');
				badge.className = 'badge text-bg-warning ms-1';
				badge.textContent = t('Expired');
				nameCell.appendChild(badge);
			}
			row.appendChild(nameCell);

			const tagCell = document.createElement('td');
			if (paste.tag) {
				const badge = document.createElement('span');
				badge.className = 'badge text-bg-secondary';
				badge.textContent = paste.tag;
				tagCell.appendChild(badge);
			} else {
				tagCell.textContent = '—';
			}
			row.appendChild(tagCell);

			row.appendChild(cell(formatDate(paste.created)));
			row.appendChild(cell(paste.expire_date ? formatDate(paste.expire_date) : t('Never')));
			row.appendChild(cell(formatSize(paste.size), 'text-end'));

			const actions = document.createElement('td');
			actions.className = 'text-end';
			const group = document.createElement('div');
			group.className = 'btn-group';
			group.appendChild(actionButton(t('Expiration'), 'btn-outline-secondary', function () {
				const days = window.prompt(t('Validity in days (0 = no expiration):'), '7');
				if (days === null || days === '') {
					return;
				}
				const expire = parseInt(days, 10);
				if (isNaN(expire) || expire < 0) {
					showMessage('adminerror', t('Invalid number of days.'));
					return;
				}
				runAction('expire', { id: paste.id, expire: expire * 86400 }, t('The expiration was updated.'));
			}));
			group.appendChild(actionButton(t('Delete'), 'btn-outline-danger', function () {
				if (!window.confirm(t('Delete this document permanently?'))) {
					return;
				}
				runAction('delete', { id: paste.id }, t('The document was deleted.'));
			}));
			actions.appendChild(group);
			row.appendChild(actions);

			body.appendChild(row);
		});
		if (pastes.length === 0) {
			const row = document.createElement('tr');
			const empty = cell(t('No documents found.'));
			empty.colSpan = 6;
			empty.className = 'text-center text-body-secondary';
			row.appendChild(empty);
			body.appendChild(row);
		}
	}

	/**
	 * load and render the paste list
	 */
	async function loadPastes() {
		hideMessage('adminerror');
		try {
			const data = await api('list', filters(), 'GET');
			state.page = data.page;
			state.pages = data.pages;
			updateTags(data.tags);
			renderPastes(data.items);
			byId('prevpage').disabled = data.page <= 1;
			byId('nextpage').disabled = data.page >= data.pages;
			byId('paginationinfo').textContent = data.total === 0 ?
				t('No documents found.') :
				data.total + ' ' + (data.total === 1 ? t('document') : t('documents')) +
					' — ' + t('Page') + ' ' + data.page + '/' + data.pages;
		} catch (error) {
			if (error.message === t('Not authenticated.') || error.message.indexOf('authenticated') !== -1) {
				window.location.reload();
				return;
			}
			showMessage('adminerror', error.message);
		}
	}

	/**
	 * setup the administration interface
	 */
	function setupAdmin() {
		const form = byId('filterform');
		if (!form) {
			return;
		}
		form.addEventListener('submit', function (event) {
			event.preventDefault();
			state.page = 1;
			loadPastes();
		});
		byId('resetfilters').addEventListener('click', function () {
			window.setTimeout(function () {
				state.page = 1;
				loadPastes();
			}, 0);
		});
		byId('sort').addEventListener('change', function () {
			state.page = 1;
			loadPastes();
		});
		byId('perpage').addEventListener('change', function () {
			state.page = 1;
			loadPastes();
		});
		byId('prevpage').addEventListener('click', function () {
			if (state.page > 1) {
				--state.page;
				loadPastes();
			}
		});
		byId('nextpage').addEventListener('click', function () {
			if (state.page < state.pages) {
				++state.page;
				loadPastes();
			}
		});
		byId('logout').addEventListener('click', async function () {
			try {
				await api('logout', {}, 'POST');
				window.location.reload();
			} catch (error) {
				showMessage('adminerror', error.message);
			}
		});
		loadPastes();
	}

	if (document.body.dataset.authenticated === '1') {
		setupAdmin();
	} else {
		setupLogin();
	}
})();
