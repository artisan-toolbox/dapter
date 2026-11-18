import { computed, createCommentVNode, defineComponent, effectScope, getCurrentInstance, guardReactiveProps, inject, isRef, nextTick, normalizeProps, onBeforeUnmount, onMounted, provide, renderSlot, shallowRef, toValue, unref, useId, watch } from "vue";
import { HttpNetworkError, router } from "@inertiajs/core";
import { useHttp, usePage } from "@inertiajs/vue3";
import { route } from "ziggy-js";
// package.json
var version$1 = "1.0.0-alpha.1";
// resources/js/protocol.ts
const protocolError = () => ({
	kind: "protocol",
	code: "invalid_response",
	message: "The data source returned an invalid response."
});
function isObject(value) {
	return typeof value === "object" && value !== null && !Array.isArray(value) && (Object.getPrototypeOf(value) === Object.prototype || Object.getPrototypeOf(value) === null);
}
function dictionary() {
	const result = {};
	Object.setPrototypeOf(result, null);
	return result;
}
function object(value) {
	if (!isObject(value)) throw new Error("Invalid object");
	return value;
}
function keys(value, names) {
	if (Object.keys(value).length !== names.length || names.some((name) => !Object.hasOwn(value, name))) throw new Error("Invalid properties");
}
function integer(value, minimum = 1) {
	if (typeof value !== "number" || !Number.isSafeInteger(value) || value < minimum) throw new Error("Invalid integer");
	return value;
}
function fixed(value, expected) {
	if (value !== expected) throw new Error("Invalid value");
}
function intent(current, perPage) {
	return {
		version: 1,
		fields: {},
		global: {},
		searchMatch: "all",
		order: { kind: "default" },
		paging: {
			mode: "offset",
			current,
			per_page: perPage
		}
	};
}
function parseIntent(value) {
	const state = object(value);
	keys(state, [
		"version",
		"fields",
		"global",
		"searchMatch",
		"order",
		"paging"
	]);
	fixed(state.version, 1);
	keys(object(state.fields), []);
	keys(object(state.global), []);
	fixed(state.searchMatch, "all");
	const order = object(state.order);
	keys(order, ["kind"]);
	fixed(order.kind, "default");
	const paging = object(state.paging);
	keys(paging, [
		"mode",
		"current",
		"per_page"
	]);
	fixed(paging.mode, "offset");
	return intent(integer(paging.current), integer(paging.per_page));
}
function validateJson(value) {
	if (value === null || typeof value === "string" || typeof value === "boolean") return;
	if (typeof value === "number" && Number.isFinite(value) && (!Number.isInteger(value) || Number.isSafeInteger(value))) return;
	if (Array.isArray(value)) {
		for (const item of value) validateJson(item);
		return;
	}
	for (const item of Object.values(object(value))) validateJson(item);
}
function freeze(value) {
	return freezeStructure(value);
}
function freezeStructure(value) {
	if (Array.isArray(value)) return Object.freeze(value.map((item) => freezeStructure(item)));
	if (isObject(value)) {
		const result = Object.fromEntries(Object.entries(value).map(([key, item]) => [key, freezeStructure(item)]));
		if (Object.getPrototypeOf(value) === null) Object.setPrototypeOf(result, null);
		return Object.freeze(result);
	}
	return value;
}
function parsePage(value) {
	const page = object(value);
	keys(page, [
		"version",
		"data",
		"requested",
		"state",
		"paging",
		"capabilities",
		...Object.hasOwn(page, "actions") ? ["actions"] : []
	]);
	fixed(page.version, 1);
	if (!Array.isArray(page.data)) throw new Error("Invalid rows");
	validateJson(page.data);
	const requested = parseIntent(page.requested);
	const state = object(page.state);
	keys(state, ["order", "paging"]);
	const order = object(state.order);
	keys(order, ["kind"]);
	fixed(order.kind, "source");
	const accepted = object(state.paging);
	keys(accepted, [
		"mode",
		"current",
		"per_page"
	]);
	fixed(accepted.mode, "offset");
	const paging = object(page.paging);
	keys(paging, [
		"mode",
		"current",
		"pages",
		"per_page",
		"total",
		"filtered",
		"has_previous",
		"has_next"
	]);
	fixed(paging.mode, "offset");
	const current = integer(paging.current), perPage = integer(paging.per_page), pages = integer(paging.pages), total = integer(paging.total, 0), filtered = integer(paging.filtered, 0);
	fixed(accepted.current, current);
	fixed(accepted.per_page, perPage);
	fixed(requested.paging.current, current);
	fixed(requested.paging.per_page, perPage);
	fixed(pages, Math.max(1, Math.ceil(filtered / perPage)));
	if (current > pages || filtered > total) throw new Error("Invalid counts");
	fixed(paging.has_previous, current > 1);
	fixed(paging.has_next, current < pages);
	fixed(page.data.length, Math.min(perPage, Math.max(0, filtered - (current - 1) * perPage)));
	const capabilities = object(page.capabilities);
	keys(capabilities, [
		"fields",
		"global",
		"paging"
	]);
	keys(object(capabilities.fields), []);
	const global = object(capabilities.global);
	keys(global, [
		"multi_order",
		"multi_order_limit",
		"searchMatch",
		"filters",
		"search",
		"order"
	]);
	fixed(global.multi_order, false);
	fixed(global.multi_order_limit, 1);
	fixed(global.filters, null);
	fixed(global.search, null);
	fixed(global.order, null);
	const search = object(global.searchMatch);
	keys(search, ["default", "allowed"]);
	fixed(search.default, "all");
	if (!Array.isArray(search.allowed) || search.allowed.length !== 1 || search.allowed[0] !== "all") throw new Error("Invalid match");
	const sizes = object(capabilities.paging);
	keys(sizes, [
		"mode",
		"per_page",
		"per_page_options"
	]);
	fixed(sizes.mode, "offset");
	const defaultPerPage = integer(sizes.per_page);
	if (!Array.isArray(sizes.per_page_options) || sizes.per_page_options.length === 0) throw new Error("Invalid sizes");
	const options = sizes.per_page_options.map((item) => integer(item));
	if (new Set(options).size !== options.length || !options.includes(perPage) || !options.includes(defaultPerPage)) throw new Error("Invalid sizes");
	return freeze({
		data: page.data,
		...Object.hasOwn(page, "actions") ? { actions: parseActions(page.actions) } : {},
		requested,
		state: {
			order: { kind: "source" },
			paging: {
				mode: "offset",
				current,
				per_page: perPage
			}
		},
		paging: {
			mode: "offset",
			current,
			pages,
			per_page: perPage,
			total,
			filtered,
			has_previous: current > 1,
			has_next: current < pages
		},
		options
	});
}
function failureKind(value) {
	return value === "access" || value === "validation" || value === "unsupported_operation" || value === "server" ? value : null;
}
function parseFailure(value, version = 1) {
	try {
		const envelope = object(value);
		keys(envelope, Object.hasOwn(envelope, "errors") ? [
			"version",
			"error",
			"errors"
		] : ["version", "error"]);
		fixed(envelope.version, version);
		const error = object(envelope.error);
		if (version === 2 && error.kind === "cursor_expired") {
			keys(envelope, ["version", "error"]);
			keys(error, [
				"kind",
				"code",
				"message",
				"recovery"
			]);
			if (error.code !== "cursor_expired" || error.recovery !== "restart" || error.message !== "The cursor has expired. Restart pagination to continue.") return null;
			return {
				kind: "cursor_expired",
				code: "cursor_expired",
				message: error.message,
				recovery: "restart"
			};
		}
		keys(error, [
			"kind",
			"code",
			"message"
		]);
		const kind = failureKind(error.kind);
		if (!kind || typeof error.code !== "string" || !error.code || typeof error.message !== "string" || !error.message) return null;
		if (Object.hasOwn(envelope, "errors")) {
			const errors = object(envelope.errors);
			keys(errors, version === 2 && error.code === "invalid_cursor" ? ["_dapter_kind", "_dapter_code"] : ["_dapter_kind"]);
			if (version === 2 && error.code === "invalid_cursor" && (!Array.isArray(errors._dapter_code) || errors._dapter_code.length !== 1 || errors._dapter_code[0] !== "invalid_cursor")) return null;
			if (!Array.isArray(errors._dapter_kind) || errors._dapter_kind.length !== 1) return null;
			fixed(errors._dapter_kind[0], kind);
		}
		return {
			kind,
			code: error.code,
			message: error.message
		};
	} catch {
		return null;
	}
}
function validationFailure(errors, version = 1) {
	try {
		const input = object(errors);
		const marker = input._dapter_kind;
		const code = input._dapter_code;
		const kind = Array.isArray(marker) && marker.length === 1 ? failureKind(marker[0]) : null;
		if (kind) return {
			kind,
			code: version === 2 && kind === "validation" && Array.isArray(code) && code.length === 1 && code[0] === "invalid_cursor" ? "invalid_cursor" : "request_rejected",
			message: kind === "access" ? "Access to this data source was denied." : "The data source rejected the request."
		};
	} catch {
		return protocolError();
	}
	return protocolError();
}
// resources/js/actions.ts
function defineActions(definitions) {
	return definitions;
}
function exact$2(input, required, optional = []) {
	if (required.some((key) => !Object.hasOwn(input, key)) || Object.keys(input).some((key) => !required.includes(key) && !optional.includes(key))) throw new Error("Invalid action metadata");
}
function identifier$1(value) {
	if (typeof value !== "string" || !/^[A-Za-z_][A-Za-z_0-9]*$/.test(value)) throw new Error("Invalid action identifier");
	return value;
}
function endpoint(value) {
	if (typeof value !== "string" || !value || Array.from(value).some((character) => character.charCodeAt(0) <= 32) || value.startsWith("//")) throw new Error("Invalid action URL");
	const url = new URL(value, "http://dapter.invalid");
	if (!["http:", "https:"].includes(url.protocol) || url.username || url.password) throw new Error("Invalid action URL");
	return value;
}
function parseActions(value) {
	const result = dictionary();
	for (const [id, raw] of Object.entries(object(value))) {
		identifier$1(id);
		const input = object(raw), method = input.method;
		if (method !== "post" && method !== "put" && method !== "patch" && method !== "delete") throw new Error("Invalid action method");
		if (!Array.isArray(input.transports) || !input.transports.length) throw new Error("Invalid action transports");
		const transports = input.transports.map((mode) => {
			if (mode === "visit" || mode === "http") return mode;
			throw new Error("Invalid action transport");
		});
		if (new Set(transports).size !== transports.length) throw new Error("Duplicate action transport");
		if (input.target === null) {
			exact$2(input, [
				"method",
				"transports",
				"target",
				"url"
			]);
			result[id] = {
				method,
				transports,
				target: null,
				url: endpoint(input.url)
			};
		} else {
			exact$2(input, [
				"method",
				"transports",
				"target"
			]);
			const target = object(input.target);
			exact$2(target, [
				"input",
				"valueType",
				"values"
			]);
			const selector = identifier$1(target.input), valueType = target.valueType;
			if (valueType !== "string" && valueType !== "integer" || !Array.isArray(target.values) || !target.values.length) throw new Error("Invalid action target");
			const values = target.values.map((raw) => {
				const item = object(raw);
				exact$2(item, ["value", "url"]);
				const value = item.value;
				if (valueType === "string" ? typeof value !== "string" : typeof value !== "number" || !Number.isSafeInteger(value)) throw new Error("Invalid action target value");
				if (typeof value !== "string" && typeof value !== "number") throw new Error("Invalid action target value");
				return {
					value,
					url: endpoint(item.url)
				};
			});
			if (new Set(values.map((item) => item.value)).size !== values.length) throw new Error("Duplicate action target");
			result[id] = {
				method,
				transports,
				target: {
					input: selector,
					valueType,
					values
				}
			};
		}
	}
	if (!Object.keys(result).length) throw new Error("Empty action metadata");
	return freeze(result);
}
function convertible(value, ancestors) {
	if (value === null || value === void 0 || typeof value === "string" || typeof value === "boolean" || typeof value === "bigint") return true;
	if (typeof value === "number") return Number.isFinite(value);
	if (value instanceof Date) return !Number.isNaN(value.valueOf());
	if (typeof Blob !== "undefined" && value instanceof Blob) return true;
	if (typeof value !== "object" || ancestors.has(value)) return false;
	ancestors.add(value);
	let accepted = false;
	try {
		accepted = (Array.isArray(value) ? value : Object.values(object(value))).every((item) => convertible(item, ancestors));
	} catch {
		accepted = false;
	}
	ancestors.delete(value);
	return accepted;
}
function payload(value) {
	if (typeof FormData !== "undefined" && value instanceof FormData) throw new Error("Use a native payload record instead of FormData");
	const input = object(value), result = dictionary();
	for (const [key, item] of Object.entries(input)) {
		if (!convertible(item, /* @__PURE__ */ new Set())) throw new Error("Invalid action input");
		result[key] = item;
	}
	return result;
}
function safeOptions(value, mode) {
	if (value === void 0) return;
	const input = object(value);
	if ([
		"url",
		"method",
		"data",
		"headers",
		"source",
		"transport",
		"target",
		...mode === "http" ? [
			"optimistic",
			"preserveScroll",
			"preserveState",
			"replace",
			"only",
			"except",
			"reset"
		] : []
	].some((key) => Object.hasOwn(input, key))) throw new Error("Reserved action option");
}
function nativeInputKey(value, blobs, ancestors = /* @__PURE__ */ new Set()) {
	if (value === null || typeof value !== "object") return `${typeof value}:${String(value)}`;
	if (typeof Blob !== "undefined" && value instanceof Blob) {
		if (!blobs.has(value)) blobs.set(value, blobs.size);
		return `blob:${blobs.get(value)}`;
	}
	if (value instanceof Date) return `date:${value.valueOf()}`;
	if (ancestors.has(value)) throw new Error("Invalid action input");
	ancestors.add(value);
	try {
		if (typeof FormData !== "undefined" && value instanceof FormData) return `form:${JSON.stringify(Array.from(value.entries(), ([key, item]) => [key, nativeInputKey(item, blobs, ancestors)]))}`;
		if (Array.isArray(value)) return `array:${JSON.stringify(value.map((item) => nativeInputKey(item, blobs, ancestors)))}`;
		return `record:${JSON.stringify(Object.entries(object(value)).map(([key, item]) => [key, nativeInputKey(item, blobs, ancestors)]))}`;
	} finally {
		ancestors.delete(value);
	}
}
function createActionController(options) {
	const state = shallowRef({
		pending: 0,
		error: null,
		validation: null
	});
	function allowed() {
		const metadata = options.metadata();
		if (!metadata) return null;
		const definitions = options.definitions();
		if (!definitions) return metadata;
		const result = dictionary();
		for (const [id, action] of Object.entries(metadata)) {
			const definition = Object.hasOwn(definitions, id) ? definitions[id] : void 0;
			if (!action || !definition) continue;
			const transports = action.transports.filter((mode) => definition.transports.includes(mode));
			if (transports.length) result[id] = {
				...action,
				transports
			};
		}
		return Object.keys(result).length ? freeze(result) : null;
	}
	function prepare(id, input, mode, captured, native) {
		if (!options.active() || options.epoch() !== captured) throw new Error("Inactive action owner");
		safeOptions(native, mode);
		const action = allowed()?.[id];
		if (!action || !action.transports.includes(mode)) throw new Error("Unavailable action");
		const definition = options.definitions()?.[id], data = payload(definition?.decodeInput ? definition.decodeInput(input) : input);
		return {
			...authorize(id, data, mode, captured),
			data,
			definition
		};
	}
	function authorize(id, data, mode, captured) {
		if (!options.active() || options.epoch() !== captured) throw new Error("Inactive action owner");
		const action = allowed()?.[id];
		if (!action || !action.transports.includes(mode)) throw new Error("Unavailable action");
		let url;
		if (action.target === null) url = action.url;
		else {
			const value = data[action.target.input];
			const selected = action.target.values.find((item) => item.value === value);
			if (!selected) throw new Error("Unavailable action target");
			url = selected.url;
		}
		if (!options.active() || options.epoch() !== captured) throw new Error("Inactive action owner");
		return {
			action,
			url
		};
	}
	function trackWrite(captured) {
		const current = () => options.active() && options.epoch() === captured;
		let phase = "preflight";
		function start() {
			if (!current() || phase !== "preflight") return;
			phase = "pending";
			state.value = {
				pending: state.value.pending + 1,
				error: null,
				validation: null
			};
		}
		function end(outcome) {
			if (!current()) return;
			if (phase === "settled") {
				if (outcome?.status === "failed") state.value = {
					...state.value,
					error: outcome.error,
					validation: null
				};
				return;
			}
			const pending = phase === "pending" ? state.value.pending - 1 : state.value.pending;
			phase = "settled";
			state.value = {
				pending,
				error: outcome?.status === "failed" || outcome?.status === "rejected" ? outcome.error : null,
				validation: outcome?.status === "validation" ? outcome.errors : null
			};
		}
		return {
			start,
			end
		};
	}
	function controls() {
		const available = allowed();
		if (!available) return null;
		const captured = options.epoch();
		return Object.freeze({
			available,
			...state.value,
			visit(id, input, native) {
				let prepared;
				try {
					prepared = prepare(id, input, "visit", captured, native);
				} catch (error) {
					if (options.active() && options.epoch() === captured) state.value = {
						...state.value,
						error
					};
					return;
				}
				const { start, end } = trackWrite(captured);
				function guardVisit(visit) {
					const blobs = /* @__PURE__ */ new Map(), owned = {
						url: visit.url.href,
						method: visit.method,
						data: nativeInputKey(visit.data, blobs),
						headers: nativeInputKey(visit.headers, blobs)
					};
					return () => {
						const current = authorize(id, prepared.data, "visit", captured);
						if (current.url !== prepared.url || current.action.method !== prepared.action.method || visit.url.href !== owned.url || visit.method !== owned.method || nativeInputKey(visit.data, blobs) !== owned.data || nativeInputKey(visit.headers, blobs) !== owned.headers) throw new Error("Reserved action override");
					};
				}
				try {
					router.visit(prepared.url, {
						...native,
						method: prepared.action.method,
						data: prepared.data,
						headers: Object.freeze({
							"X-Dapter-Action": "1",
							"X-Dapter-Transport": "visit"
						}),
						onBefore(visit) {
							try {
								const check = guardVisit(visit);
								const result = native?.onBefore?.(visit);
								if (result === false) end({
									status: "rejected",
									commit: "not_started",
									error: /* @__PURE__ */ new Error("Action cancelled before dispatch")
								});
								if (result !== false) try {
									check();
								} catch (error) {
									end({
										status: "rejected",
										commit: "not_started",
										error
									});
									return false;
								}
								return result;
							} catch (error) {
								end({
									status: "failed",
									commit: "unknown",
									error
								});
								throw error;
							}
						},
						onStart(visit) {
							start();
							const check = guardVisit(visit);
							try {
								native?.onStart?.(visit);
							} catch (error) {
								end({
									status: "failed",
									commit: "unknown",
									error
								});
								throw error;
							}
							try {
								check();
							} catch (error) {
								end({
									status: "rejected",
									commit: "not_started",
									error
								});
								throw error;
							}
						},
						onError(errors) {
							try {
								const result = native?.onError?.(errors);
								end({
									status: "validation",
									commit: "unknown",
									errors
								});
								return result;
							} catch (error) {
								end({
									status: "failed",
									commit: "unknown",
									error
								});
								throw error;
							}
						},
						onCancel() {
							try {
								native?.onCancel?.();
								end({
									status: "cancelled",
									commit: "unknown"
								});
							} catch (error) {
								end({
									status: "failed",
									commit: "unknown",
									error
								});
								throw error;
							}
						},
						onSuccess(page) {
							try {
								return native?.onSuccess?.(page);
							} catch (error) {
								end({
									status: "failed",
									commit: "unknown",
									error
								});
								throw error;
							}
						},
						onHttpException(response) {
							try {
								const result = native?.onHttpException?.(response);
								end({
									status: "failed",
									commit: "unknown",
									error: response
								});
								return result;
							} catch (error) {
								end({
									status: "failed",
									commit: "unknown",
									error
								});
								throw error;
							}
						},
						onNetworkError(error) {
							try {
								const result = native?.onNetworkError?.(error);
								end({
									status: "failed",
									commit: "unknown",
									error
								});
								return result;
							} catch (cause) {
								end({
									status: "failed",
									commit: "unknown",
									error: cause
								});
								throw cause;
							}
						},
						onFinish(visit) {
							try {
								native?.onFinish?.(visit);
								end();
							} catch (error) {
								end({
									status: "failed",
									commit: "unknown",
									error
								});
								throw error;
							}
						}
					});
				} catch (error) {
					end({
						status: "failed",
						commit: "unknown",
						error
					});
				}
			},
			async http(id, input, native) {
				let prepared;
				try {
					prepared = prepare(id, input, "http", captured, native);
				} catch (error) {
					const outcome = {
						status: "rejected",
						commit: "not_started",
						error
					};
					if (options.active() && options.epoch() === captured) state.value = {
						...state.value,
						error
					};
					return outcome;
				}
				const { start, end } = trackWrite(captured);
				start();
				let validation = null, cancelled = false, blocked = false, callbackFailed = false;
				function invokeCallback(callback) {
					try {
						callback();
					} catch (error) {
						callbackFailed = true;
						throw error;
					}
				}
				try {
					const response = await useHttp({}).transform(() => prepared.data).withAllErrors()[prepared.action.method](prepared.url, {
						...native,
						headers: Object.freeze({
							"X-Dapter-Action": "1",
							"X-Dapter-Transport": "http"
						}),
						onBefore() {
							const result = native?.onBefore?.();
							if (result === false) blocked = true;
							if (!blocked) try {
								const current = authorize(id, prepared.data, "http", captured);
								if (current.url !== prepared.url || current.action.method !== prepared.action.method) throw new Error("Unavailable action");
							} catch {
								blocked = true;
								return false;
							}
							return result;
						},
						onStart() {
							invokeCallback(() => native?.onStart?.());
							try {
								const current = authorize(id, prepared.data, "http", captured);
								if (current.url !== prepared.url || current.action.method !== prepared.action.method) throw new Error("Unavailable action");
							} catch (error) {
								blocked = true;
								throw error;
							}
						},
						onError(errors) {
							validation = errors;
							invokeCallback(() => native?.onError?.(errors));
						},
						onCancel() {
							cancelled = true;
							invokeCallback(() => native?.onCancel?.());
						},
						onFinish() {
							invokeCallback(() => native?.onFinish?.());
						}
					});
					const outcome = validation ? {
						status: "validation",
						commit: "unknown",
						errors: validation
					} : cancelled ? {
						status: "cancelled",
						commit: "unknown"
					} : blocked ? {
						status: "rejected",
						commit: "not_started",
						error: /* @__PURE__ */ new Error("Action cancelled before dispatch")
					} : {
						status: "completed",
						commit: "confirmed",
						data: prepared.definition?.decodeResult ? prepared.definition.decodeResult(response) : response
					};
					end(outcome);
					return outcome;
				} catch (error) {
					const outcome = callbackFailed ? {
						status: "failed",
						commit: "unknown",
						error
					} : validation ? {
						status: "validation",
						commit: "unknown",
						errors: validation
					} : blocked ? {
						status: "rejected",
						commit: "not_started",
						error
					} : cancelled ? {
						status: "cancelled",
						commit: "unknown"
					} : {
						status: "failed",
						commit: "unknown",
						error
					};
					end(outcome);
					return outcome;
				}
			}
		});
	}
	return {
		controls,
		reset() {
			state.value = {
				pending: 0,
				error: null,
				validation: null
			};
		}
	};
}
// resources/js/infinite-observer.ts
function observeInfinite(options, owner) {
	const axis = options.axis ?? "y";
	let version = 0;
	let credits = 2;
	let visible = false;
	let episodeOffset = null;
	let left = false;
	let running = null;
	let resume = () => {};
	let disconnect = () => {};
	function offset(root) {
		return axis === "x" ? root.scrollLeft : root.scrollTop;
	}
	function contains(root, sentinel) {
		const bounds = root.getBoundingClientRect(), target = sentinel.getBoundingClientRect();
		return axis === "x" ? target.right >= bounds.left && target.left <= bounds.right : target.bottom >= bounds.top && target.top <= bounds.bottom;
	}
	async function fill(root, sentinel, active) {
		if (running === active || !visible || credits === 0 || owner.status() !== "idle") return;
		running = active;
		try {
			while (active === version && owner.ready() && toValue(options.root) === root && toValue(options.sentinel) === sentinel && root.contains(sentinel) && visible && credits > 0 && owner.status() === "idle") {
				credits--;
				const outcome = await owner.append();
				if (active !== version || outcome.status !== "success") break;
				visible = contains(root, sentinel);
				if (!visible) left = true;
			}
		} finally {
			if (running === active) running = null;
		}
	}
	const stop = watch(() => [
		toValue(options.root),
		toValue(options.sentinel),
		owner.ready()
	], ([root, sentinel, ready]) => {
		disconnect();
		disconnect = () => {};
		const active = ++version;
		running = null;
		resume = () => {};
		visible = false;
		if (!ready) {
			credits = 2;
			episodeOffset = null;
			left = false;
			return;
		}
		if (!root || !sentinel || typeof IntersectionObserver === "undefined") return;
		let observation = null;
		let connection = 0;
		function connect() {
			if (active !== version || !owner.ready() || toValue(options.root) !== root || toValue(options.sentinel) !== sentinel || !root || !sentinel) return;
			if (!root.contains(sentinel)) {
				connection++;
				observation?.disconnect();
				observation = null;
				visible = false;
				return;
			}
			if (observation) return;
			episodeOffset = offset(root);
			left = false;
			const attached = ++connection;
			observation = new IntersectionObserver((entries) => {
				if (active !== version || attached !== connection || !owner.ready() || toValue(options.root) !== root || toValue(options.sentinel) !== sentinel || !root.contains(sentinel)) return;
				const entry = entries.find((value) => value.target === sentinel);
				if (!entry) return;
				if (!entry.isIntersecting) {
					visible = false;
					left = true;
					return;
				}
				if (episodeOffset === null) episodeOffset = offset(root);
				else if (left && offset(root) !== episodeOffset) {
					credits = 2;
					episodeOffset = offset(root);
				}
				left = false;
				visible = true;
				fill(root, sentinel, active);
			}, { root });
			observation.observe(sentinel);
		}
		resume = () => {
			if (active !== version || !visible || !root.contains(sentinel)) return;
			visible = contains(root, sentinel);
			if (visible) fill(root, sentinel, active);
			else left = true;
		};
		const mutations = typeof MutationObserver === "undefined" ? null : new MutationObserver(connect);
		mutations?.observe(root, {
			childList: true,
			subtree: true
		});
		connect();
		disconnect = () => {
			connection++;
			observation?.disconnect();
			mutations?.disconnect();
		};
	}, {
		flush: "post",
		immediate: true
	});
	const stopStatus = watch(owner.status, (status) => {
		if (status === "idle") resume();
	}, { flush: "post" });
	return () => {
		version++;
		stopStatus();
		disconnect();
		stop();
	};
}
// resources/js/records.ts
function exact$1(value, keys) {
	if (Object.keys(value).length !== keys.length || keys.some((key) => !Object.hasOwn(value, key))) throw new Error("Invalid record properties");
}
function unicode(value) {
	return !/[\uD800-\uDBFF](?![\uDC00-\uDFFF])|(?<![\uD800-\uDBFF])[\uDC00-\uDFFF]/u.test(value);
}
function parseRows(value) {
	if (!Array.isArray(value) || Object.keys(value).length !== value.length) throw new Error("Invalid rows");
	function check(input, depth) {
		if (depth > 100) throw new Error("Invalid row depth");
		if (typeof input === "string" && !unicode(input)) throw new Error("Invalid Unicode");
		if (Array.isArray(input)) {
			if (Object.keys(input).length !== input.length) throw new Error("Sparse rows");
			for (const item of input) check(item, depth + 1);
		} else if (input !== null && typeof input === "object") for (const [key, item] of Object.entries(input)) {
			if (!unicode(key)) throw new Error("Invalid Unicode");
			check(item, depth + 1);
		}
	}
	for (const row of value) check(row, 0);
	validateJson(value);
	return freeze(value);
}
function decodeRows(rows, decoder) {
	return parseRows(rows.map((row) => decoder ? decoder(row) : row));
}
function id(value) {
	if (typeof value === "number" && Number.isSafeInteger(value)) return value === 0 ? 0 : value;
	if (typeof value === "string" && value.length > 0 && unicode(value)) return value;
	throw new Error("Invalid row identity");
}
function revision(value) {
	if (typeof value === "string" && /^(0|[1-9][0-9]*)$/.exec(value)?.[0] === value && (value.length < 20 || value.length === 20 && value <= "18446744073709551615")) return value;
	throw new Error("Invalid row revision");
}
function newer(left, right) {
	return left.length > right.length || left.length === right.length && left > right;
}
function parseRecords(value, length) {
	const input = object(value);
	if (input.mode === "unidentified") {
		exact$1(input, ["mode"]);
		return Object.freeze({ mode: "unidentified" });
	}
	if (input.mode !== "identified" && input.mode !== "revisioned") throw new Error("Invalid record mode");
	exact$1(input, input.mode === "identified" ? ["mode", "rows"] : [
		"mode",
		"format",
		"rows"
	]);
	if (!Array.isArray(input.rows) || input.rows.length !== length || Object.keys(input.rows).length !== length) throw new Error("Misaligned records");
	const seen = /* @__PURE__ */ new Set();
	const rows = input.rows.map((value) => {
		const entry = object(value);
		exact$1(entry, input.mode === "identified" ? ["id"] : ["id", "revision"]);
		const identity = id(entry.id);
		if (seen.has(identity)) throw new Error("Duplicate identity");
		seen.add(identity);
		return {
			id: identity,
			...input.mode === "revisioned" ? { revision: revision(entry.revision) } : {}
		};
	});
	if (input.mode === "identified") return freeze({
		mode: "identified",
		rows
	});
	if (input.format !== "uint64-decimal") throw new Error("Invalid revision format");
	return freeze({
		mode: "revisioned",
		format: "uint64-decimal",
		rows: rows.map((row) => ({
			id: row.id,
			revision: revision(row.revision)
		}))
	});
}
function parseChannel(value) {
	if (value === null) return null;
	const input = object(value);
	exact$1(input, [
		"type",
		"name",
		"event"
	]);
	if (input.type !== "private" && input.type !== "public" || typeof input.name !== "string" || input.name.length > 128 || /^[A-Za-z0-9_=-]{1,64}(?:\.[A-Za-z0-9_=-]{1,64})*$/.exec(input.name)?.[0] !== input.name || /^(private-|presence-)/.test(input.name) || input.event !== ".dapter.updated") throw new Error("Invalid channel");
	return Object.freeze({
		type: input.type,
		name: input.name,
		event: input.event
	});
}
function sameChannel(left, right) {
	return left === right || left !== null && right !== null && left.type === right.type && left.name === right.name && left.event === right.event;
}
function parseMetadata(value, length) {
	const input = object(value);
	exact$1(input, ["records", "channel"]);
	const records = parseRecords(input.records, length), channel = parseChannel(input.channel);
	if (channel !== null && records.mode === "unidentified") throw new Error("Unidentified live rows");
	return freeze({
		records,
		channel
	});
}
function parseUpdate(value, source, channel) {
	const input = object(value);
	if (typeof input.source === "string" && input.source !== source) return null;
	if (!sameChannel(parseChannel(input.channel), channel)) return null;
	exact$1(input, [
		"version",
		"eventId",
		"source",
		"channel",
		"data",
		"records"
	]);
	if (input.version !== 1 || input.source !== source || typeof input.eventId !== "string" || /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/.exec(input.eventId)?.[0] !== input.eventId) throw new Error("Invalid update");
	const data = parseRows(input.data), records = parseRecords(input.records, data.length);
	if (data.length !== 1 || records.mode === "unidentified") throw new Error("Invalid update rows");
	const bytes = new TextEncoder().encode(JSON.stringify(input)).length;
	if (bytes > 65536) throw new Error("Oversized update");
	return freeze({
		eventId: input.eventId,
		data,
		records,
		bytes
	});
}
// resources/js/protocol-v2.ts
function identifier(value) {
	if (!/^[A-Za-z_][A-Za-z_0-9]*$/.test(value)) throw new Error("Invalid identifier");
}
function exact(value, required, optional = []) {
	if (required.some((key) => !Object.hasOwn(value, key)) || Object.keys(value).some((key) => !required.includes(key) && !optional.includes(key))) throw new Error("Invalid properties");
}
function positive(value) {
	if (typeof value !== "number" || !Number.isSafeInteger(value) || value < 1) throw new Error("Invalid integer");
	return value;
}
function scalar(value) {
	if (value === null || typeof value === "string" || typeof value === "boolean" || typeof value === "number" && Number.isFinite(value) && Math.abs(value) <= Number.MAX_SAFE_INTEGER) return value;
	throw new Error("Invalid scalar");
}
function match(value) {
	if (value === "contains" || value === "equals" || value === "starts_with" || value === "ends_with" || value === "full_text") return value;
	throw new Error("Invalid match");
}
function sort(value) {
	if (value === "native" || value === "regular" || value === "string" || value === "natural" || value === "numeric" || value === "locale") return value;
	throw new Error("Invalid sort");
}
function direction(value) {
	if (value === "asc" || value === "desc") return value;
	throw new Error("Invalid direction");
}
function list(value, parse) {
	if (!Array.isArray(value) || !value.length) throw new Error("Invalid list");
	const parsed = value.map(parse);
	if (new Set(parsed).size !== parsed.length) throw new Error("Duplicate values");
	return parsed;
}
function parseFullText(value) {
	const input = object(value);
	exact(input, [
		"engine",
		"configuration",
		"input",
		"maxBytes",
		"ranking"
	]);
	if (input.engine !== "pgsql" || typeof input.configuration !== "string" || !/^[A-Za-z_][A-Za-z_0-9]*\.[A-Za-z_][A-Za-z_0-9]*$/.test(input.configuration) || input.input !== "plain_text" || input.maxBytes !== 512 || input.ranking !== "filter_only") throw new Error("Invalid full-text policy");
	return {
		engine: "pgsql",
		configuration: input.configuration,
		input: "plain_text",
		maxBytes: 512,
		ranking: "filter_only"
	};
}
function parseLocale(value) {
	const input = object(value);
	exact(input, [
		"locale",
		"validLocale",
		"actualLocale",
		"icuVersion",
		"strength",
		"normalization",
		"numeric"
	]);
	if (typeof input.locale !== "string" || !input.locale || typeof input.validLocale !== "string" || !input.validLocale || typeof input.actualLocale !== "string" || !input.actualLocale || typeof input.icuVersion !== "string" || !input.icuVersion || input.strength !== "tertiary" || input.normalization !== true || input.numeric !== false) throw new Error("Invalid locale policy");
	return {
		locale: input.locale,
		validLocale: input.validLocale,
		actualLocale: input.actualLocale,
		icuVersion: input.icuVersion,
		strength: "tertiary",
		normalization: true,
		numeric: false
	};
}
function parseSearch(value) {
	if (value === null) return null;
	const input = object(value);
	exact(input, ["matchTypes", "valueType"], ["fullText"]);
	const valueType = input.valueType;
	if (valueType !== "string" && valueType !== "integer" && valueType !== "number") throw new Error("Invalid value type");
	const matchTypes = list(input.matchTypes, match);
	if (valueType !== "string" && (matchTypes.length !== 1 || matchTypes[0] !== "equals")) throw new Error("Invalid search");
	if (matchTypes.includes("full_text") !== Object.hasOwn(input, "fullText")) throw new Error("Mismatched full-text policy");
	return {
		matchTypes,
		valueType,
		...Object.hasOwn(input, "fullText") ? { fullText: parseFullText(input.fullText) } : {}
	};
}
function parseOrder(value) {
	if (value === null) return null;
	const input = object(value);
	exact(input, ["sortTypes", "directions"], ["locale"]);
	const sortTypes = list(input.sortTypes, sort);
	if (Object.hasOwn(input, "locale") && !sortTypes.includes("locale")) throw new Error("Mismatched locale policy");
	return {
		sortTypes,
		directions: list(input.directions, direction),
		...Object.hasOwn(input, "locale") ? { locale: parseLocale(input.locale) } : {}
	};
}
function parseFilters(value) {
	if (value === null) return null;
	const result = dictionary();
	for (const [key, raw] of Object.entries(object(value))) {
		identifier(key);
		const input = object(raw);
		if (input.multiple === false) {
			exact(input, ["values", "multiple"]);
			result[key] = {
				values: list(input.values, scalar),
				multiple: false
			};
		} else {
			exact(input, [
				"values",
				"multiple",
				"empty"
			]);
			if (input.multiple !== true || input.empty !== "clear" && input.empty !== "reject") throw new Error("Invalid filter");
			result[key] = {
				values: list(input.values, scalar),
				multiple: true,
				empty: input.empty
			};
		}
	}
	if (!Object.keys(result).length) throw new Error("Empty filters");
	return result;
}
function parseDefinition(value) {
	const input = object(value);
	exact(input, [
		"fields",
		"global",
		"paging"
	]);
	const fields = dictionary();
	for (const [key, raw] of Object.entries(object(input.fields))) {
		identifier(key);
		const field = object(raw);
		exact(field, [
			"search",
			"order",
			"filters"
		]);
		fields[key] = {
			search: parseSearch(field.search),
			order: parseOrder(field.order),
			filters: parseFilters(field.filters)
		};
	}
	const global = object(input.global);
	exact(global, [
		"multi_order",
		"multi_order_limit",
		"searchMatch",
		"filters",
		"search",
		"order"
	], ["stability"]);
	const search = object(global.searchMatch);
	exact(search, ["default", "allowed"]);
	const searchDefault = searchMatch(search.default), allowed = list(search.allowed, searchMatch);
	if (!allowed.includes(searchDefault) || typeof global.multi_order !== "boolean") throw new Error("Invalid global capabilities");
	const limit = positive(global.multi_order_limit);
	if (!global.multi_order && limit !== 1) throw new Error("Invalid order limit");
	let globalSearch = null;
	if (global.search !== null) {
		const raw = object(global.search);
		exact(raw, ["valueType", "targets"]);
		if (raw.valueType !== "string") throw new Error("Invalid global search");
		const targets = dictionary();
		for (const [id, value] of Object.entries(object(raw.targets))) {
			identifier(id);
			const target = object(value);
			exact(target, ["valueType", "matchType"], ["fullText"]);
			const parsed = parseSearch({
				valueType: target.valueType,
				matchTypes: [target.matchType],
				...Object.hasOwn(target, "fullText") ? { fullText: target.fullText } : {}
			});
			if (!parsed) throw new Error("Invalid target");
			targets[id] = {
				valueType: parsed.valueType,
				matchType: match(target.matchType),
				...parsed.fullText ? { fullText: parsed.fullText } : {}
			};
		}
		if (!Object.keys(targets).length) throw new Error("Empty targets");
		globalSearch = {
			valueType: "string",
			targets
		};
	}
	let globalOrder = null;
	if (global.order !== null) {
		const raw = object(global.order);
		exact(raw, ["presets", "pagingModes"]);
		if (!Array.isArray(raw.presets) || !raw.presets.length || !Array.isArray(raw.pagingModes) || raw.pagingModes.length !== 1 || raw.pagingModes[0] !== "offset") throw new Error("Invalid presets");
		const presets = raw.presets.map((value) => {
			const preset = object(value);
			exact(preset, ["id", "label"]);
			if (typeof preset.id !== "string" || typeof preset.label !== "string" || !preset.label) throw new Error("Invalid preset");
			identifier(preset.id);
			return {
				id: preset.id,
				label: preset.label
			};
		});
		if (new Set(presets.map((preset) => preset.id)).size !== presets.length) throw new Error("Duplicate presets");
		globalOrder = {
			presets,
			pagingModes: ["offset"]
		};
	}
	let stability;
	if (global.stability !== void 0) {
		const raw = object(global.stability);
		exact(raw, ["prefix", "identity"]);
		const labels = (value) => {
			if (!Array.isArray(value)) throw new Error("Invalid labels");
			return value.map((label) => {
				const term = object(label);
				exact(term, [
					"id",
					"direction",
					"sortType"
				]);
				if (typeof term.id !== "string" || !term.id) throw new Error("Invalid label");
				return {
					id: term.id,
					direction: direction(term.direction),
					sortType: sort(term.sortType)
				};
			});
		};
		stability = {
			prefix: labels(raw.prefix),
			identity: labels(raw.identity)
		};
	}
	const paging = object(input.paging);
	const mode = paging.mode;
	if (mode !== "offset" && mode !== "simple" && mode !== "cursor") throw new Error("Invalid paging");
	exact(paging, [
		"mode",
		"per_page",
		"per_page_options",
		...mode === "offset" ? [] : ["counts"],
		...mode === "cursor" ? ["expires_after"] : []
	]);
	if (mode !== "offset" && paging.counts !== "unknown" && paging.counts !== "exact") throw new Error("Invalid counts policy");
	if (mode === "cursor" && paging.expires_after !== 900) throw new Error("Invalid cursor lifetime");
	if (mode === "cursor" && (Object.values(fields).some((field) => field?.order?.sortTypes.includes("locale")) || [...stability?.prefix ?? [], ...stability?.identity ?? []].some((term) => term.sortType === "locale"))) throw new Error("Unsupported cursor locale");
	const per_page = positive(paging.per_page), per_page_options = list(paging.per_page_options, positive);
	if (!per_page_options.includes(per_page)) throw new Error("Invalid sizes");
	return freeze({
		fields,
		global: {
			multi_order: global.multi_order,
			multi_order_limit: limit,
			searchMatch: {
				default: searchDefault,
				allowed
			},
			search: globalSearch,
			filters: parseFilters(global.filters),
			order: globalOrder,
			...stability ? { stability } : {}
		},
		paging: mode === "offset" ? {
			mode,
			per_page,
			per_page_options
		} : mode === "simple" ? {
			mode,
			per_page,
			per_page_options,
			counts: paging.counts === "exact" ? "exact" : "unknown"
		} : {
			mode,
			per_page,
			per_page_options,
			counts: paging.counts === "exact" ? "exact" : "unknown",
			expires_after: 900
		}
	});
}
function parseRequestedV2(value, definition) {
	const input = object(value);
	exact(input, [
		"version",
		"fields",
		"global",
		"searchMatch",
		"order",
		"paging"
	]);
	if (input.version !== 2) throw new Error("Invalid state");
	const grouping = searchMatch(input.searchMatch);
	if (definition && !definition.global.searchMatch.allowed.includes(grouping)) throw new Error("Unavailable grouping");
	const globalInput = object(input.global);
	exact(globalInput, [], ["search", "filters"]);
	const global = parseGlobalSelection(globalInput, definition);
	const position = parsePosition(object(input.paging));
	const per_page = position.per_page;
	if (definition && position.mode !== definition.paging.mode) throw new Error("Mismatched mode");
	if (definition && !definition.paging.per_page_options.includes(per_page)) throw new Error("Unavailable size");
	const fields = dictionary();
	for (const [name, raw] of Object.entries(object(input.fields))) {
		identifier(name);
		const field = object(raw);
		exact(field, [], ["search", "filters"]);
		const capability = definition?.fields[name];
		if (definition && !capability) throw new Error("Unavailable field");
		let search;
		if (Object.hasOwn(field, "search")) {
			const selection = object(field.search);
			exact(selection, ["value", "matchType"]);
			const value = scalar(selection.value), matchType = match(selection.matchType);
			if (typeof value !== "string" && typeof value !== "number" || capability && (!capability.search || !capability.search.matchTypes.includes(matchType) || !validSearchValue(value, capability.search.valueType))) throw new Error("Invalid search");
			if (matchType === "full_text" && !validFullTextInput(value)) throw new Error("Invalid full-text input");
			if (value !== "") search = {
				value,
				matchType
			};
		}
		const filters = dictionary();
		if (Object.hasOwn(field, "filters")) for (const [id, rawFilter] of Object.entries(object(field.filters))) {
			identifier(id);
			const selection = object(rawFilter);
			exact(selection, ["value"]);
			const available = capability?.filters?.[id];
			if (definition && !available) throw new Error("Unavailable filter");
			const value = Array.isArray(selection.value) ? selection.value.map(scalar) : scalar(selection.value);
			if (available) {
				if (available.multiple !== Array.isArray(value)) throw new Error("Invalid multiplicity");
				const values = Array.isArray(value) ? value : [value];
				if (!values.length && available.multiple && available.empty === "reject" || values.some((item) => !available.values.includes(item))) throw new Error("Unavailable value");
				if (!values.length) continue;
			}
			filters[id] = { value: Array.isArray(value) ? [...new Set(value)] : value };
		}
		if (search || Object.keys(filters).length) fields[name] = {
			...search ? { search } : {},
			...Object.keys(filters).length ? { filters } : {}
		};
	}
	const orderInput = object(input.order);
	let order;
	if (orderInput.kind === "default") {
		exact(orderInput, ["kind"]);
		order = { kind: "default" };
	} else if (orderInput.kind === "preset") {
		exact(orderInput, ["kind", "id"]);
		if (typeof orderInput.id !== "string") throw new Error("Invalid preset");
		identifier(orderInput.id);
		if (definition && !definition.global.order?.presets.some((preset) => preset.id === orderInput.id)) throw new Error("Unavailable preset");
		if (position.mode !== "offset") throw new Error("Preset paging unavailable");
		order = {
			kind: "preset",
			id: orderInput.id
		};
	} else {
		exact(orderInput, ["kind", "terms"]);
		if (orderInput.kind !== "fields" || !Array.isArray(orderInput.terms) || !orderInput.terms.length) throw new Error("Invalid order");
		const terms = orderInput.terms.map((raw) => {
			const term = object(raw);
			exact(term, [
				"field",
				"direction",
				"sortType"
			]);
			if (typeof term.field !== "string") throw new Error("Invalid field");
			identifier(term.field);
			const selected = {
				field: term.field,
				direction: direction(term.direction),
				sortType: sort(term.sortType)
			};
			const available = definition?.fields[selected.field]?.order;
			if (definition && (!available || !available.directions.includes(selected.direction) || !available.sortTypes.includes(selected.sortType))) throw new Error("Unavailable order");
			if (position.mode === "cursor" && selected.sortType !== "native") throw new Error("Unsupported cursor sort");
			return selected;
		});
		if (new Set(terms.map((term) => term.field)).size !== terms.length || definition && (terms.length > definition.global.multi_order_limit || !definition.global.multi_order && terms.length > 1)) throw new Error("Invalid order limit");
		order = {
			kind: "fields",
			terms
		};
	}
	return freeze({
		version: 2,
		fields,
		global,
		searchMatch: grouping,
		order,
		paging: position
	});
}
function validFullTextInput(value) {
	return typeof value === "string" && !value.includes("\0") && !/[\uD800-\uDFFF]/u.test(value) && new TextEncoder().encode(value).length <= 512;
}
function validSearchValue(value, type) {
	return type === "string" ? typeof value === "string" : typeof value === "number" && (type === "number" || Number.isSafeInteger(value));
}
function searchMatch(value) {
	if (value === "all" || value === "any") return value;
	throw new Error("Invalid grouping");
}
function parseGlobalSelection(input, definition) {
	let search;
	if (Object.hasOwn(input, "search")) {
		const raw = object(input.search);
		exact(raw, ["value"]);
		if (typeof raw.value !== "string" || definition && !definition.global.search) throw new Error("Unavailable global search");
		if (definition && Object.values(definition.global.search?.targets ?? {}).some((target) => target.matchType === "full_text") && !validFullTextInput(raw.value)) throw new Error("Invalid full-text input");
		if (raw.value !== "") search = { value: raw.value };
	}
	const filters = dictionary();
	if (Object.hasOwn(input, "filters")) for (const [id, raw] of Object.entries(object(input.filters))) {
		identifier(id);
		const selection = object(raw);
		exact(selection, ["value"]);
		const available = definition?.global.filters?.[id];
		if (definition && !available) throw new Error("Unavailable filter");
		const value = Array.isArray(selection.value) ? [...new Set(selection.value.map(scalar))] : scalar(selection.value);
		if (available) {
			if (available.multiple !== Array.isArray(value)) throw new Error("Invalid multiplicity");
			const values = Array.isArray(value) ? value : [value];
			if (values.some((item) => !available.values.includes(item)) || !values.length && available.multiple && available.empty === "reject") throw new Error("Unavailable value");
			if (!values.length) continue;
		}
		filters[id] = { value };
	}
	return {
		...search ? { search } : {},
		...Object.keys(filters).length ? { filters } : {}
	};
}
function sameGlobalSearch(left, right) {
	return right !== null && Object.keys(left.targets).length === Object.keys(right.targets).length && Object.entries(left.targets).every(([id, target]) => {
		const source = right.targets[id];
		return source?.valueType === target.valueType && source.matchType === target.matchType && JSON.stringify(source.fullText) === JSON.stringify(target.fullText);
	});
}
function assertNarrowing(original, narrowed) {
	if (narrowed.global.multi_order && !original.global.multi_order || narrowed.global.multi_order_limit > original.global.multi_order_limit || narrowed.global.searchMatch.allowed.some((mode) => !original.global.searchMatch.allowed.includes(mode)) || narrowed.global.searchMatch.default !== original.global.searchMatch.default || narrowed.global.search !== null && !sameGlobalSearch(narrowed.global.search, original.global.search) || (narrowed.global.order?.presets.some((preset) => !original.global.order?.presets.some((source) => source.id === preset.id && source.label === preset.label)) ?? false) || JSON.stringify(narrowed.global.stability) !== JSON.stringify(original.global.stability) || original.paging.mode !== narrowed.paging.mode || ("counts" in original.paging ? original.paging.counts : null) !== ("counts" in narrowed.paging ? narrowed.paging.counts : null) || ("expires_after" in original.paging ? original.paging.expires_after : null) !== ("expires_after" in narrowed.paging ? narrowed.paging.expires_after : null) || original.paging.per_page !== narrowed.paging.per_page || narrowed.paging.per_page_options.some((size) => !original.paging.per_page_options.includes(size))) throw new Error("Expanded definition");
	for (const [id, filter] of Object.entries(narrowed.global.filters ?? {})) {
		const source = original.global.filters?.[id];
		if (filter && (!source || source.multiple !== filter.multiple || filter.multiple && (!source.multiple || source.empty !== filter.empty) || filter.values.some((value) => !source.values.includes(value)))) throw new Error("Expanded global filter");
	}
	for (const [name, field] of Object.entries(narrowed.fields)) {
		if (!field) continue;
		const source = original.fields[name];
		if (!source) throw new Error("Expanded field");
		if (field.search && (!source.search || field.search.valueType !== source.search.valueType || field.search.matchTypes.some((value) => !source.search?.matchTypes.includes(value)) || field.search.matchTypes.includes("full_text") && JSON.stringify(field.search.fullText) !== JSON.stringify(source.search.fullText))) throw new Error("Expanded search");
		if (field.order && (!source.order || field.order.sortTypes.some((value) => !source.order?.sortTypes.includes(value)) || field.order.directions.some((value) => !source.order?.directions.includes(value)) || field.order.sortTypes.includes("locale") && JSON.stringify(field.order.locale) !== JSON.stringify(source.order.locale))) throw new Error("Expanded order");
		for (const [id, filter] of Object.entries(field.filters ?? {})) {
			if (!filter) continue;
			const sourceFilter = source.filters?.[id];
			if (!sourceFilter || sourceFilter.multiple !== filter.multiple || filter.multiple && (!sourceFilter.multiple || sourceFilter.empty !== filter.empty) || filter.values.some((value) => !sourceFilter.values.includes(value))) throw new Error("Expanded filter");
		}
	}
}
function parsePageV2(value) {
	const input = object(value);
	exact(input, [
		"version",
		"data",
		"requested",
		"state",
		"paging",
		"capabilities",
		"metadata"
	], ["actions"]);
	if (input.version !== 2) throw new Error("Invalid version");
	const definition = parseDefinition(input.capabilities), requested = parseRequestedV2(input.requested, definition);
	const state = object(input.state);
	exact(state, ["order", "paging"]);
	const order = object(state.order);
	let acceptedOrder;
	if (order.kind === "source") {
		exact(order, ["kind"]);
		if (requested.order.kind !== "default") throw new Error("Mismatched order");
		acceptedOrder = { kind: "source" };
	} else if (order.kind === "preset") {
		exact(order, [
			"kind",
			"id",
			"identity",
			"opaque"
		]);
		if (requested.order.kind !== "preset" || order.id !== requested.order.id || order.identity !== `preset:${requested.order.id}` || order.opaque !== true) throw new Error("Mismatched preset");
		acceptedOrder = {
			kind: "preset",
			id: requested.order.id,
			identity: order.identity,
			opaque: true
		};
	} else {
		exact(order, ["kind", "terms"]);
		if (order.kind !== "terms" || !Array.isArray(order.terms)) throw new Error("Mismatched order");
		const terms = order.terms.map((raw) => {
			const term = object(raw);
			exact(term, [
				"field",
				"direction",
				"sortType"
			], ["role"]);
			if (typeof term.field !== "string") throw new Error("Invalid term");
			if (term.role !== void 0 && (!definition.global.stability || term.role !== "prefix" && term.role !== "tie" || !(term.role === "prefix" ? definition.global.stability.prefix : definition.global.stability.identity).some((label) => label.id === term.field && label.direction === term.direction && label.sortType === term.sortType))) throw new Error("Invalid stability role");
			return {
				field: term.field,
				direction: direction(term.direction),
				sortType: sort(term.sortType),
				...term.role === "prefix" || term.role === "tie" ? { role: term.role } : {}
			};
		});
		const selected = terms.filter((term) => !term.role);
		const prefix = definition.global.stability?.prefix ?? [], identities = definition.global.stability?.identity ?? [];
		const expectedPrefix = prefix.map((term) => ({
			field: term.id,
			direction: term.direction,
			sortType: term.sortType,
			role: "prefix"
		}));
		const actualPrefix = terms.filter((term) => term.role === "prefix");
		const tieIndices = terms.filter((term) => term.role === "tie").map((term) => identities.findIndex((label) => label.id === term.field));
		if (JSON.stringify(actualPrefix) !== JSON.stringify(expectedPrefix) || terms.some((term, index) => index < prefix.length ? term.role !== "prefix" : index < prefix.length + selected.length ? term.role !== void 0 : term.role !== "tie") || tieIndices.some((index, position) => position > 0 && index <= (tieIndices[position - 1] ?? -1))) throw new Error("Mismatched stability order");
		if (JSON.stringify(selected) !== JSON.stringify(requested.order.kind === "fields" ? requested.order.terms : [])) throw new Error("Mismatched order");
		acceptedOrder = {
			kind: "terms",
			terms
		};
	}
	const position = parsePosition(state.paging);
	if (JSON.stringify(position) !== JSON.stringify(requested.paging)) throw new Error("Mismatched paging");
	const data = parseRows(input.data);
	const metadata = parseMetadata(input.metadata, data.length);
	if (definition.paging.mode !== "offset") {
		const paging = parseAdvancedPaging(input.paging, requested.paging, definition.paging.counts);
		if (!Array.isArray(input.data) || input.data.length > paging.per_page) throw new Error("Invalid rows");
		validateJson(input.data);
		if (paging.mode === "cursor" && input.data.length === 0 && (paging.has_previous || paging.has_next)) throw new Error("Invalid empty cursor window");
		return freeze({
			data: input.data,
			requested,
			state: {
				order: acceptedOrder,
				paging: requested.paging
			},
			paging,
			options: definition.paging.per_page_options,
			definition,
			metadata,
			...Object.hasOwn(input, "actions") ? { actions: parseActions(input.actions) } : {}
		});
	}
	const { metadata: _metadata, ...base } = input;
	return freeze({
		...parsePage({
			...base,
			version: 1,
			requested: {
				version: 1,
				fields: {},
				global: {},
				searchMatch: "all",
				order: { kind: "default" },
				paging: requested.paging
			},
			state: {
				order: { kind: "source" },
				paging: requested.paging
			},
			capabilities: {
				fields: {},
				global: {
					multi_order: false,
					multi_order_limit: 1,
					searchMatch: {
						default: "all",
						allowed: ["all"]
					},
					search: null,
					filters: null,
					order: null
				},
				paging: definition.paging
			}
		}),
		requested,
		state: {
			order: acceptedOrder,
			paging: requested.paging
		},
		definition,
		metadata
	});
}
function cursor(value) {
	if (value === null || typeof value === "string" && value.length > 0 && value.length <= 16384) return value;
	throw new Error("Invalid cursor");
}
function parsePosition(value) {
	const input = object(value);
	if (input.mode === "cursor") {
		exact(input, [
			"mode",
			"cursor",
			"per_page"
		]);
		return {
			mode: "cursor",
			cursor: cursor(input.cursor),
			per_page: positive(input.per_page)
		};
	}
	exact(input, [
		"mode",
		"current",
		"per_page"
	]);
	if (input.mode !== "offset" && input.mode !== "simple") throw new Error("Invalid paging");
	const current = positive(input.current), per_page = positive(input.per_page);
	if (input.mode === "simple" && !Number.isSafeInteger((current - 1) * per_page + per_page + 1)) throw new Error("Unsafe paging");
	return {
		mode: input.mode,
		current,
		per_page
	};
}
function parseAdvancedPaging(value, position, counts) {
	const input = object(value);
	exact(input, [
		"mode",
		"current",
		"per_page",
		"pages",
		"total",
		"filtered",
		"previous_cursor",
		"next_cursor",
		"has_previous",
		"has_next",
		...position.mode === "cursor" ? ["cursor"] : []
	]);
	if (position.mode === "offset" || input.mode !== position.mode || input.per_page !== position.per_page || input.pages !== null || typeof input.has_previous !== "boolean" || typeof input.has_next !== "boolean") throw new Error("Mismatched paging");
	let total = null, filtered = null;
	if (counts === "exact") {
		total = nonnegative(input.total);
		filtered = nonnegative(input.filtered);
		if (filtered > total) throw new Error("Invalid counts");
	} else if (input.total !== null || input.filtered !== null) throw new Error("Invalid counts");
	const common = {
		pages: null,
		total,
		filtered,
		has_previous: input.has_previous,
		has_next: input.has_next
	};
	if (position.mode === "simple") {
		if (input.current !== position.current || input.previous_cursor !== null || input.next_cursor !== null || input.has_previous !== position.current > 1) throw new Error("Invalid simple paging");
		return {
			...common,
			...position,
			previous_cursor: null,
			next_cursor: null
		};
	}
	const previous_cursor = cursor(input.previous_cursor), next_cursor = cursor(input.next_cursor);
	if (input.current !== null || input.cursor !== position.cursor || input.has_previous !== (previous_cursor !== null) || input.has_next !== (next_cursor !== null)) throw new Error("Invalid cursor paging");
	return {
		...common,
		...position,
		current: null,
		previous_cursor,
		next_cursor
	};
}
function nonnegative(value) {
	if (value === 0) return 0;
	return positive(value);
}
// resources/js/requested-state.ts
function stateKey(value) {
	if (Array.isArray(value)) return `[${value.map(stateKey).join(",")}]`;
	if (value !== null && typeof value === "object") return `{${Object.keys(value).sort().map((key) => `${JSON.stringify(key)}:${stateKey(Reflect.get(value, key))}`).join(",")}}`;
	return JSON.stringify(value) ?? "undefined";
}
function reduceRequested(current, command, definition, pages) {
	if (command.kind === "replace") return parseRequestedV2(command.value, definition);
	if (command.kind === "cursor") {
		if (current.paging.mode !== "cursor") throw new Error("Invalid cursor");
		return parseRequestedV2({
			...current,
			paging: {
				...current.paging,
				cursor: command.value
			}
		}, definition);
	}
	if (command.kind === "page") {
		if (!Number.isSafeInteger(command.value) || command.value < 1 || current.paging.mode === "cursor" || pages !== null && command.value > pages) throw new Error("Invalid page");
		return parseRequestedV2({
			...current,
			paging: {
				...current.paging,
				current: command.value
			}
		}, definition);
	}
	const first = current.paging.mode === "cursor" ? {
		...current.paging,
		cursor: null
	} : {
		...current.paging,
		current: 1
	};
	if (command.kind === "size") return parseRequestedV2({
		...current,
		paging: {
			...current.paging.per_page === command.value ? current.paging : first,
			per_page: command.value
		}
	}, definition);
	if (command.options !== void 0) exact(object(command.options), [], ["page"]);
	if (!command.clear && (command.value === null || command.value === void 0)) throw new Error("Invalid selection");
	if (command.options && command.options.page !== void 0 && command.options.page !== "first" && command.options.page !== "preserve") throw new Error("Invalid page policy");
	if (command.options?.page === "preserve" && current.paging.mode !== "offset") throw new Error("Page preservation unavailable");
	const paging = command.options?.page === "preserve" ? current.paging : first;
	if (command.kind === "preset" && current.paging.mode !== "offset") throw new Error("Preset paging unavailable");
	if (command.kind === "order") {
		if (command.value !== null) exact(object(command.value), ["direction", "sortType"], ["multiSort"]);
		const active = current.order.kind === "fields" && current.order.terms.some((term) => term.field === command.field);
		if (!command.value && !active) return current;
		if (command.value?.multiSort && (!definition.global.multi_order || current.order.kind === "preset")) throw new Error("Multiple order unavailable");
		const existing = current.order.kind === "fields" ? current.order.terms : [];
		let terms = command.value?.multiSort ? [...existing] : [];
		if (command.value) {
			const selected = {
				field: command.field,
				direction: command.value.direction,
				sortType: command.value.sortType
			};
			const index = terms.findIndex((term) => term.field === command.field);
			if (index < 0) terms.push(selected);
			else terms[index] = selected;
		} else terms = existing.filter((term) => term.field !== command.field);
		const candidate = parseRequestedV2({
			...current,
			order: terms.length ? {
				kind: "fields",
				terms
			} : { kind: "default" }
		}, definition);
		if (stateKey(candidate) === stateKey(current)) return current;
		return freeze({
			...candidate,
			paging
		});
	}
	if (command.kind === "globalSearch" || command.kind === "globalFilter" || command.kind === "preset" || command.kind === "grouping") {
		let global = current.global, order = current.order, searchMatch = current.searchMatch;
		if (command.kind === "grouping") searchMatch = command.value;
		else if (command.kind === "preset") {
			if (command.value) exact(object(command.value), ["id"]);
			if (!command.value && order.kind !== "preset") return current;
			order = command.value ? {
				kind: "preset",
				id: command.value.id
			} : { kind: "default" };
		} else if (command.kind === "globalSearch") {
			if (command.value) exact(object(command.value), ["value"]);
			const { search: _search, ...rest } = global;
			global = command.value ? {
				...rest,
				search: command.value
			} : rest;
		} else {
			const filters = Object.assign(dictionary(), global.filters);
			if (command.value) filters[command.id] = command.value;
			else delete filters[command.id];
			const { filters: _filters, ...rest } = global;
			global = Object.keys(filters).length ? {
				...rest,
				filters
			} : rest;
		}
		const candidate = parseRequestedV2({
			...current,
			global,
			order,
			searchMatch
		}, definition);
		return stateKey(candidate) === stateKey(current) ? current : freeze({
			...candidate,
			paging
		});
	}
	const fields = Object.assign(dictionary(), current.fields), existing = fields[command.field] ?? {};
	if (command.kind === "search") {
		if (!command.value && !existing.search) return current;
		const { search: _search, ...rest } = existing;
		fields[command.field] = command.value ? {
			...rest,
			search: command.value
		} : rest;
	} else {
		if (!command.value && !existing.filters?.[command.id]) return current;
		const filters = Object.assign(dictionary(), existing.filters);
		if (command.value) filters[command.id] = command.value;
		else delete filters[command.id];
		const { filters: _filters, ...rest } = existing;
		fields[command.field] = Object.keys(filters).length ? {
			...rest,
			filters
		} : rest;
	}
	const candidate = parseRequestedV2({
		...current,
		fields,
		paging: current.paging
	}, definition);
	if (stateKey(candidate) === stateKey(current)) return current;
	return freeze({
		...candidate,
		paging
	});
}
// resources/js/window.ts
function boundary(state) {
	return stateKey(state.paging);
}
function firstPosition(state) {
	if (!state) return null;
	return freeze({
		...state,
		paging: state.paging.mode === "cursor" ? {
			...state.paging,
			cursor: null
		} : {
			...state.paging,
			current: 1
		}
	});
}
function nextPosition(result) {
	const { page, window } = result;
	if (!page.paging.has_next || window?.stopped) return null;
	const paging = page.paging.mode === "cursor" ? {
		mode: page.paging.mode,
		cursor: page.paging.next_cursor,
		per_page: page.paging.per_page
	} : {
		mode: page.paging.mode,
		current: page.paging.current + 1,
		per_page: page.paging.per_page
	};
	const next = freeze({
		...page.requested,
		paging
	});
	return window?.consumed.includes(boundary(next)) ? null : next;
}
function combineActions(left, right) {
	if (!left && !right) return void 0;
	const actions = dictionary();
	const ids = /* @__PURE__ */ new Set([...Object.keys(left ?? {}), ...Object.keys(right ?? {})]);
	for (const id of ids) {
		const old = left?.[id], next = right?.[id];
		if (!old || !next) {
			const exclusive = old ?? next;
			if (!exclusive || exclusive.target === null) throw new Error("Changed global action");
			actions[id] = exclusive;
			continue;
		}
		if (old.method !== next.method || stateKey([...old.transports].sort()) !== stateKey([...next.transports].sort())) throw new Error("Changed action transport");
		if (old.target === null || next.target === null) {
			if (old.target !== null || next.target !== null || old.url !== next.url) throw new Error("Changed global action");
			actions[id] = next;
			continue;
		}
		if (old.target.input !== next.target.input || old.target.valueType !== next.target.valueType) throw new Error("Changed action target");
		const values = [...old.target.values];
		for (const item of next.target.values) {
			const previous = values.find((entry) => entry.value === item.value);
			if (previous && previous.url !== item.url) throw new Error("Conflicting action URL");
			if (!previous) values.push(item);
		}
		actions[id] = {
			...next,
			target: {
				...next.target,
				values
			}
		};
	}
	return freeze(actions);
}
function combineRecords(left, right) {
	if (left.mode === "unidentified" && right.mode === "unidentified") return right;
	if (left.mode === "identified" && right.mode === "identified") return {
		mode: "identified",
		rows: [...left.rows, ...right.rows]
	};
	if (left.mode === "revisioned" && right.mode === "revisioned") return {
		mode: "revisioned",
		format: right.format,
		rows: [...left.rows, ...right.rows]
	};
	throw new Error("Changed record mode");
}
function acceptWindow(previous, incoming, append, enabled) {
	if (!enabled) return incoming;
	if (!append || !previous?.window) return {
		...incoming,
		window: {
			start: incoming.page.requested,
			consumed: [boundary(incoming.page.requested)],
			stopped: false
		}
	};
	if (stateKey(previous.page.definition) !== stateKey(incoming.page.definition) || !sameChannel(previous.page.metadata.channel, incoming.page.metadata.channel)) throw new Error("Changed window capabilities");
	if (previous.window.consumed.includes(boundary(incoming.page.requested))) return {
		...previous,
		window: {
			...previous.window,
			stopped: true
		}
	};
	const records = combineRecords(previous.page.metadata.records, incoming.page.metadata.records);
	const actions = combineActions(previous.page.actions, incoming.page.actions);
	return freeze({
		...incoming,
		window: {
			...previous.window,
			consumed: [...previous.window.consumed, boundary(incoming.page.requested)]
		},
		data: [...previous.data, ...incoming.data],
		page: {
			...incoming.page,
			...actions ? { actions } : {},
			data: [...previous.page.data, ...incoming.page.data],
			metadata: {
				...incoming.page.metadata,
				records
			}
		}
	});
}
function replaceOccurrences(result, update, decoded) {
	const current = result.page.metadata.records;
	const incoming = update.records.rows[0];
	if (current.mode === "unidentified" || !incoming || current.mode !== update.records.mode) return result;
	const indexes = /* @__PURE__ */ new Set();
	let records = current;
	if (current.mode === "revisioned" && update.records.mode === "revisioned") {
		const next = update.records.rows[0];
		if (!next) return result;
		records = {
			...current,
			rows: current.rows.map((row, index) => {
				if (row.id !== next.id || !newer(next.revision, row.revision)) return row;
				indexes.add(index);
				return next;
			})
		};
	} else current.rows.forEach((row, index) => {
		if (row.id === incoming.id) indexes.add(index);
	});
	if (!indexes.size) return result;
	return Object.freeze({
		...result,
		data: Object.freeze(result.data.map((row, index) => indexes.has(index) ? decoded[0] : row)),
		page: Object.freeze({
			...result.page,
			data: Object.freeze(result.page.data.map((row, index) => indexes.has(index) ? update.data[0] : row)),
			metadata: Object.freeze({
				...result.page.metadata,
				records: freeze(records)
			})
		})
	});
}
// resources/js/realtime.ts
const bridges = /* @__PURE__ */ new WeakMap();
function subscribe(echo, descriptor, update, failed) {
	const channel = descriptor.type === "private" ? echo.private(descriptor.name) : echo.channel(descriptor.name);
	let sinks = bridges.get(channel);
	if (!sinks) {
		sinks = /* @__PURE__ */ new Set();
		bridges.set(channel, sinks);
		const owners = sinks;
		try {
			channel.error((error) => {
				[...owners].forEach((sink) => sink(error));
			});
		} catch (cause) {
			bridges.delete(channel);
			throw cause;
		}
	}
	const owners = sinks;
	owners.add(failed);
	try {
		channel.listen(descriptor.event, update);
	} catch (cause) {
		owners.delete(failed);
		channel.stopListening(descriptor.event, update);
		throw cause;
	}
	let active = true;
	return () => {
		if (!active) return;
		active = false;
		owners.delete(failed);
		channel.stopListening(descriptor.event, update);
	};
}
// resources/js/browser.ts
const owners = /* @__PURE__ */ new Map();
const retired = /* @__PURE__ */ new Map();
const positions = /* @__PURE__ */ new Map();
let queue = Promise.resolve();
let navigation = 0;
let authoritativeUrl = null;
let expectedUrl = null;
let pendingWrites = 0;
let observer = null;
let releaseNavigation = null;
function absolute(value) {
	return new URL(value, window.location.href).href;
}
function observe() {
	if (observer || typeof window === "undefined") return;
	authoritativeUrl = window.location.href;
	const page = usePage();
	observer = effectScope(true);
	observer.run(() => watch(() => page.url, (url) => {
		if (!url) return;
		const actual = absolute(url);
		if (actual !== expectedUrl) {
			authoritativeUrl = actual;
			navigation++;
		}
	}, { flush: "sync" }));
	releaseNavigation = router.on("navigate", (event) => {
		const actual = absolute(event.detail.page.url);
		if (actual !== expectedUrl) {
			authoritativeUrl = actual;
			navigation++;
		}
	});
}
function releaseObserver() {
	if (owners.size || pendingWrites) return;
	observer?.stop();
	observer = null;
	releaseNavigation?.();
	releaseNavigation = null;
	authoritativeUrl = null;
}
function patch(url, key, value) {
	const target = new URL(url);
	if (value === null) target.searchParams.delete(key);
	else target.searchParams.set(key, value);
	return target.href;
}
function clientVisit(url, replace) {
	expectedUrl = url;
	return new Promise((resolve) => {
		try {
			router[replace ? "replace" : "push"]({
				url,
				preserveState: true,
				preserveScroll: true,
				flash: (flash) => flash,
				onFinish: () => resolve()
			});
		} catch {
			resolve();
		}
	});
}
async function publish(owner, value, replace, current) {
	const epoch = navigation;
	pendingWrites++;
	const task = queue.then(async () => {
		if (!owner.alive || owners.get(owner.key) !== owner || !current() || epoch !== navigation) return;
		const target = patch(window.location.href, owner.key, value);
		if (target === window.location.href) {
			owner.settled = value;
			owner.lastUrl = target;
			return;
		}
		await clientVisit(target, replace);
		if (owner.alive && owners.get(owner.key) === owner && current() && epoch === navigation && window.location.href === target) {
			owner.settled = value;
			owner.lastUrl = target;
			authoritativeUrl = target;
			return;
		}
		let written = target;
		let correctionEpoch = epoch;
		while (window.location.href === written) {
			const live = owners.get(owner.key);
			const corrected = correctionEpoch !== navigation && authoritativeUrl !== null ? authoritativeUrl : patch(window.location.href, owner.key, live?.settled ?? owner.settled);
			if (corrected === window.location.href) break;
			correctionEpoch = navigation;
			written = corrected;
			await clientVisit(corrected, true);
			if (correctionEpoch === navigation) break;
		}
	}).catch(() => {}).finally(() => {
		expectedUrl = null;
		pendingWrites--;
		releaseObserver();
	});
	queue = task;
	await task;
}
function decode$2(url, key, source) {
	const raw = url.searchParams.get(key);
	if (raw === null) return {
		snapshot: null,
		invalid: false
	};
	try {
		const value = JSON.parse(raw);
		if (typeof value !== "object" || value === null || Array.isArray(value) || !("version" in value) || value.version !== 2 || !("source" in value) || value.source !== source || !("state" in value) || Object.keys(value).length !== 3) return {
			snapshot: null,
			invalid: true
		};
		return {
			snapshot: parseRequestedV2(value.state),
			invalid: false
		};
	} catch {
		return {
			snapshot: null,
			invalid: true
		};
	}
}
function encode(source, state) {
	return JSON.stringify({
		version: 2,
		source,
		state
	});
}
function createBrowser(options) {
	let started = false;
	let disposed = false;
	let popped = false;
	let restoredPosition;
	let stopHistory;
	let interacted = false;
	const key = `dapter[${options.id}]`;
	const owner = {
		source: options.source,
		key,
		alive: false,
		settled: null,
		lastUrl: "",
		guard: void 0
	};
	function container() {
		return options.scrollContainer === void 0 ? null : toValue(options.scrollContainer);
	}
	function positionKey(url) {
		return `${options.source}\n${key}\n${url}`;
	}
	function save() {
		const element = container();
		if (element && owner.lastUrl) positions.set(positionKey(owner.lastUrl), {
			top: element.scrollTop,
			left: element.scrollLeft
		});
		while (positions.size > 200) positions.delete(positions.keys().next().value ?? "");
	}
	function restorePosition() {
		const element = container();
		if (element && restoredPosition) {
			element.scrollTop = restoredPosition.top;
			element.scrollLeft = restoredPosition.left;
		}
		restoredPosition = void 0;
	}
	function onPop() {
		save();
		popped = true;
	}
	function restore() {
		if (!popped || !owner.alive) return;
		popped = false;
		const url = new URL(window.location.href);
		const candidate = decode$2(url, key, options.source);
		restoredPosition = positions.get(positionKey(url.href));
		const same = owner.settled === url.searchParams.get(key) && owner.guard?.();
		owner.settled = url.searchParams.get(key);
		owner.lastUrl = url.href;
		interacted = owner.settled !== null;
		if (candidate.invalid) options.onInvalid();
		else if (!same) options.onRestore(candidate.snapshot);
		else nextTick().then(() => {
			if (owner.alive) restorePosition();
		});
	}
	return {
		start() {
			if (started || disposed || typeof window === "undefined") return {
				snapshot: null,
				invalid: false
			};
			started = true;
			if (!options.enabled) return {
				snapshot: null,
				invalid: false
			};
			if (owners.get(key)?.alive) throw new Error("URL-enabled adapters require independent identifiers.");
			owner.alive = true;
			owner.lastUrl = window.location.href;
			restoredPosition = positions.get(positionKey(owner.lastUrl));
			owner.settled = new URL(owner.lastUrl).searchParams.get(key);
			owners.set(key, owner);
			observe();
			window.addEventListener("popstate", onPop);
			stopHistory = router.on("navigate", restore);
			const candidate = decode$2(new URL(owner.lastUrl), key, options.source);
			const previousSource = retired.get(key);
			retired.delete(key);
			if (candidate.invalid && previousSource !== void 0 && previousSource !== options.source) {
				owner.settled = null;
				return {
					snapshot: null,
					invalid: false
				};
			}
			return candidate;
		},
		async publish(snapshot, reason, current) {
			if (disposed || !current()) return;
			save();
			owner.guard = current;
			if (options.enabled && owner.alive && typeof window !== "undefined") {
				if (![
					"initial",
					"history",
					"reload",
					"append"
				].includes(reason)) interacted = reason !== "reset";
				const absent = [
					"initial",
					"history",
					"reload",
					"append"
				].includes(reason) && owner.settled === null && !interacted;
				const value = snapshot === null || absent ? null : encode(options.source, snapshot);
				await publish(owner, value, [
					"initial",
					"history",
					"reload",
					"search",
					"replace",
					"reset",
					"append"
				].includes(reason), current);
			}
			await nextTick();
			if (disposed || !current()) return;
			if (reason === "history" || reason === "initial") restorePosition();
			else if (![
				"initial",
				"reload",
				"append"
			].includes(reason)) {
				const element = container();
				if (element) {
					element.scrollTop = 0;
					element.scrollLeft = 0;
				}
			}
		},
		reset() {
			interacted = false;
			restoredPosition = void 0;
			for (const position of positions.keys()) if (position.startsWith(`${options.source}\n${key}\n`)) positions.delete(position);
			owner.settled = null;
			if (typeof window === "undefined" || !options.enabled || !owner.alive) return Promise.resolve();
			return publish(owner, null, true, () => !disposed && owner.alive);
		},
		dispose() {
			if (disposed) return;
			save();
			disposed = true;
			owner.alive = false;
			if (owners.get(key) === owner) owners.delete(key);
			retired.set(key, options.source);
			queueMicrotask(() => {
				if (retired.get(key) === options.source) retired.delete(key);
			});
			if (typeof window !== "undefined") window.removeEventListener("popstate", onPop);
			stopHistory?.();
			releaseObserver();
		}
	};
}
// resources/js/controller-v2.ts
function decode$1(page, props) {
	const definition = parseDefinition(props.decodeDefinition ? props.decodeDefinition(freeze(page.definition)) : page.definition);
	assertNarrowing(page.definition, definition);
	parseRequestedV2(page.requested, definition);
	return {
		page,
		window: null,
		data: decodeRows(page.data, props.decodeRow),
		definition
	};
}
function project(definition, requested, apply) {
	const fields = dictionary();
	for (const [name, field] of Object.entries(definition.fields)) {
		if (!field) continue;
		const selection = requested?.fields[name];
		const term = requested?.order.kind === "fields" ? requested.order.terms.find((term) => term.field === name) : void 0;
		const mutableFilters = dictionary();
		for (const [id, available] of Object.entries(field.filters ?? {})) {
			if (!available) continue;
			mutableFilters[id] = {
				available,
				current: selection?.filters?.[id] ?? null,
				call: (value, options) => apply({
					kind: "filter",
					field: name,
					id,
					value,
					...options ? { options } : {}
				}),
				clear: (options) => apply({
					kind: "filter",
					field: name,
					id,
					value: null,
					clear: true,
					...options ? { options } : {}
				})
			};
		}
		fields[name] = {
			search: field.search ? {
				available: field.search,
				current: selection?.search ?? null,
				call: (value, options) => apply({
					kind: "search",
					field: name,
					value,
					...options ? { options } : {}
				}),
				clear: (options) => apply({
					kind: "search",
					field: name,
					value: null,
					clear: true,
					...options ? { options } : {}
				})
			} : null,
			order: field.order ? {
				available: field.order,
				current: term ? {
					direction: term.direction,
					sortType: term.sortType,
					priority: requested?.order.kind === "fields" ? requested.order.terms.indexOf(term) + 1 : 1
				} : null,
				call: (value, options) => apply({
					kind: "order",
					field: name,
					value,
					...options ? { options } : {}
				}),
				clear: (options) => apply({
					kind: "order",
					field: name,
					value: null,
					clear: true,
					...options ? { options } : {}
				})
			} : null,
			filters: field.filters ? mutableFilters : null
		};
	}
	return freeze(fields);
}
function projectGlobal(definition, requested, apply) {
	const filters = dictionary();
	for (const [id, available] of Object.entries(definition.global.filters ?? {})) {
		if (!available) continue;
		filters[id] = {
			available,
			current: requested?.global.filters?.[id] ?? null,
			call: (value, options) => apply({
				kind: "globalFilter",
				id,
				value,
				...options ? { options } : {}
			}),
			clear: (options) => apply({
				kind: "globalFilter",
				id,
				value: null,
				clear: true,
				...options ? { options } : {}
			})
		};
	}
	return freeze({
		...definition.global,
		search: definition.global.search ? {
			available: definition.global.search,
			current: requested?.global.search ?? null,
			call: (value, options) => apply({
				kind: "globalSearch",
				value,
				...options ? { options } : {}
			}),
			clear: (options) => apply({
				kind: "globalSearch",
				value: null,
				clear: true,
				...options ? { options } : {}
			})
		} : null,
		filters: definition.global.filters ? filters : null,
		order: definition.global.order ? {
			available: definition.global.order,
			current: requested?.order.kind === "preset" ? { id: requested.order.id } : null,
			call: (value, options) => apply({
				kind: "preset",
				value,
				...options ? { options } : {}
			}),
			clear: (options) => apply({
				kind: "preset",
				value: null,
				clear: true,
				...options ? { options } : {}
			})
		} : null
	});
}
function createV2Controller(props, identity) {
	if (props.searchDebounce !== void 0 && (!Number.isSafeInteger(props.searchDebounce) || props.searchDebounce < 0)) throw new Error("searchDebounce must be a nonnegative integer.");
	const session = shallowRef({
		accepted: null,
		definitionContext: null,
		requested: null,
		error: null,
		status: "idle"
	});
	const infiniteEnabled = props.infinite !== void 0 && props.infinite !== false;
	const infiniteState = shallowRef("idle");
	let appendOperation = null;
	let restartInfinite = false;
	const observedReady = shallowRef(false);
	let mounted = false, generation = 0, epoch = 0;
	let pending = null;
	const integrationError = shallowRef(null);
	let work = { kind: "idle" };
	let descriptor = null;
	let detach = null;
	let suspended = false;
	let liveVersion = 0;
	let buffer = [];
	let bufferBytes = 0;
	const eventIds = /* @__PURE__ */ new Set();
	let pollTimer = null;
	let pollingPaused = false;
	const pollingOption = props.polling;
	const polling = pollingOption && Object.freeze({ ...pollingOption });
	if (polling !== void 0 && polling !== false && (typeof polling !== "object" || polling === null || Object.keys(polling).length !== 1 || !Object.hasOwn(polling, "interval") || typeof polling.interval !== "number" || !Number.isFinite(polling.interval) || polling.interval <= 0 || polling.interval > 2147483647)) throw new Error("polling requires a positive interval at most 2147483647 milliseconds.");
	function cancelPoll() {
		if (pollTimer !== null) clearTimeout(pollTimer);
		pollTimer = null;
	}
	function eligible() {
		return mounted && !pollingPaused && !pending && work.kind === "idle" && (typeof document === "undefined" || document.visibilityState !== "hidden") && (typeof navigator === "undefined" || navigator.onLine !== false);
	}
	function schedulePoll() {
		cancelPoll();
		if (!polling || !eligible()) return;
		pollTimer = setTimeout(() => {
			pollTimer = null;
			if (!eligible()) return;
			submit(infiniteEnabled ? firstPosition(session.value.requested) : session.value.requested, true, 0, "reload", false);
		}, polling.interval);
	}
	function availability() {
		schedulePoll();
	}
	function failIntegration(code) {
		integrationError.value = Object.freeze({
			kind: "integration",
			code,
			message: "The adapter integration could not complete. Retry explicitly to recover."
		});
	}
	function discardBuffer() {
		buffer = [];
		bufferBytes = 0;
	}
	function detachListener() {
		liveVersion++;
		const old = detach;
		detach = null;
		old?.();
	}
	function clearLive(scope = "context") {
		detachListener();
		discardBuffer();
		eventIds.clear();
		if (scope === "window" && suspended) return;
		descriptor = null;
		suspended = false;
		integrationError.value = null;
	}
	function overflow() {
		discardBuffer();
		suspended = true;
		detachListener();
		failIntegration("event_overflow");
	}
	function replace(update, decoded, publish = false) {
		const result = session.value.accepted;
		if (!result || result.page.metadata.records.mode === "unidentified") return;
		if (result.page.metadata.records.mode !== update.records.mode) {
			failIntegration("invalid_event");
			return;
		}
		if (!update.records.rows[0]) return;
		const replacement = replaceOccurrences(result, update, decoded);
		if (replacement === result) return;
		const active = generation, context = epoch;
		if (publish) {
			cancelPoll();
			work = {
				kind: "publishing",
				generation: active
			};
		}
		session.value = {
			...session.value,
			accepted: replacement
		};
		if (publish) settlePublication(Promise.resolve(), active, context);
	}
	function listen(next, explicit, recover) {
		if (!sameChannel(descriptor, next)) {
			clearLive();
			descriptor = next;
		}
		if (explicit && (!suspended || recover)) {
			suspended = false;
			integrationError.value = null;
		}
		if (!descriptor || detach || suspended) return;
		if (!props.echo) {
			failIntegration("echo_missing");
			return;
		}
		const context = epoch, version = liveVersion, channel = descriptor, source = props.source;
		const current = () => mounted && epoch === context && liveVersion === version && sameChannel(descriptor, channel);
		let failed = false;
		try {
			const stop = subscribe(props.echo, channel, (value) => {
				if (!current() || suspended) return;
				let update;
				try {
					update = parseUpdate(value, source, channel);
					if (!update || eventIds.has(update.eventId)) return;
					if (session.value.accepted?.page.metadata.records.mode !== update.records.mode) throw new Error("Mismatched record mode");
				} catch {
					failIntegration("invalid_event");
					return;
				}
				let decoded;
				try {
					decoded = decodeRows(update.data, props.decodeRow);
				} catch {
					if (current()) failIntegration("decoder_failed");
					return;
				}
				if (!current() || suspended) return;
				eventIds.add(update.eventId);
				if (eventIds.size > 512) {
					const oldest = eventIds.values().next().value;
					if (oldest !== void 0) eventIds.delete(oldest);
				}
				if (pending || work.kind !== "idle") {
					if (buffer.length === 128 || bufferBytes + update.bytes > 1048576) {
						overflow();
						return;
					}
					buffer.push({
						update,
						decoded
					});
					bufferBytes += update.bytes;
				} else replace(update, decoded, true);
			}, () => {
				if (!current()) return;
				failed = true;
				suspended = true;
				discardBuffer();
				detachListener();
				failIntegration("subscription_failed");
			});
			if (failed || !current()) stop();
			else detach = stop;
		} catch {
			if (current()) {
				suspended = true;
				detachListener();
				failIntegration("subscription_failed");
			}
		}
	}
	async function settlePublication(publication, active, context) {
		const current = () => mounted && generation === active && epoch === context;
		try {
			await publication;
			await nextTick();
			if (!current()) return false;
			let replayedEvents = 0, replayedBytes = 0;
			do {
				const replay = buffer;
				replayedEvents += replay.length;
				replayedBytes += bufferBytes;
				discardBuffer();
				if (replayedEvents > 128 || replayedBytes > 1048576) {
					overflow();
					break;
				}
				for (const item of replay) {
					if (!current()) return false;
					replace(item.update, item.decoded);
				}
				await nextTick();
				if (!current()) return false;
			} while (buffer.length > 0);
			work = { kind: "idle" };
			schedulePoll();
			return true;
		} catch {
			if (!current()) return false;
			discardBuffer();
			work = { kind: "idle" };
			pollingPaused = true;
			failIntegration("render_failed");
			if (infiniteEnabled) {
				restartInfinite = true;
				infiniteState.value = "paused";
			}
			cancelPoll();
			return false;
		}
	}
	function restartPolling() {
		if (!polling || !mounted) return;
		pollingPaused = false;
		schedulePoll();
	}
	const actions = createActionController({
		metadata: () => session.value.accepted?.page.actions ?? null,
		definitions: () => props.actionDefinitions,
		active: () => mounted,
		epoch: () => epoch
	});
	const success = () => Promise.resolve({ status: "success" });
	const superseded = () => Promise.resolve({ status: "superseded" });
	function invalid() {
		return Promise.resolve({
			status: "failure",
			error: {
				kind: "validation",
				code: "invalid_state",
				message: "Choose an available operation, value, page, and page size."
			}
		});
	}
	function invalidate(resetObservation = true) {
		if (resetObservation) observedReady.value = false;
		const oldAppend = appendOperation;
		appendOperation = null;
		oldAppend?.settle({ status: "superseded" });
		cancelPoll();
		work = { kind: "idle" };
		generation++;
		const old = pending;
		pending = null;
		if (old) {
			if (old.timer !== null) clearTimeout(old.timer);
			old.cancel();
			old.settle({ status: "superseded" });
		}
	}
	function localError() {
		session.value = {
			...session.value,
			error: Object.freeze({
				kind: "validation",
				code: "invalid_state",
				message: "The saved adapter state is invalid."
			}),
			status: "error"
		};
	}
	function parseSaved(snapshot) {
		if (snapshot === null) return null;
		const restored = parseRequestedV2(snapshot, session.value.accepted?.definition);
		if (props.paging !== "source" && restored.paging.mode !== "offset") throw new Error("Source paging requires opt-in");
		return restored;
	}
	function makeBrowser() {
		return createBrowser({
			source: props.source,
			id: identity,
			enabled: props.url !== false,
			scrollContainer: () => props.scrollContainer === void 0 ? null : toValue(props.scrollContainer),
			onRestore(snapshot) {
				invalidate();
				try {
					submit(parseSaved(snapshot), true, 0, "history");
				} catch {
					localError();
				}
			},
			onInvalid() {
				invalidate();
				localError();
			}
		});
	}
	let browser = makeBrowser();
	function initial() {
		const saved = browser.start();
		if (saved.invalid) {
			localError();
			return;
		}
		try {
			submit(parseSaved(saved.snapshot), true, 0, "initial");
		} catch {
			localError();
		}
	}
	function submit(state, force = false, delay = 0, reason = "replace", explicit = true, settledOutcome = false) {
		if (!mounted) return superseded();
		if (state && props.paging !== "source" && state.paging.mode !== "offset") return invalid();
		cancelPoll();
		const key = stateKey(state);
		if (pending?.key === key) {
			if (force) pending.reason = reason;
			if (explicit) pending.explicit = true;
			if (delay === 0 && pending.timer !== null) pending.flush();
			return pending.promise;
		}
		if (!force && !pending && session.value.status === "success" && stateKey(session.value.requested) === key) {
			schedulePoll();
			return success();
		}
		invalidate(reason !== "append");
		if (infiniteEnabled && reason !== "append") {
			infiniteState.value = "idle";
			restartInfinite = false;
			clearLive("window");
			session.value = {
				...session.value,
				accepted: null
			};
		}
		const active = generation;
		work = {
			kind: delay > 0 ? "debounced" : "requesting",
			generation: active
		};
		session.value = {
			...session.value,
			requested: freeze(state),
			status: "loading",
			error: null
		};
		if (!mounted || active !== generation) return superseded();
		let resolve = () => {};
		const promise = new Promise((done) => {
			resolve = done;
		});
		let form = null;
		if (reason === "append" || settledOutcome) {
			appendOperation = {
				promise,
				settle: resolve
			};
			infiniteState.value = "loading";
		}
		async function run() {
			if (!mounted || active !== generation) return;
			work = {
				kind: "requesting",
				generation: active
			};
			form = useHttp(state ? {
				version: 2,
				state
			} : { version: 2 }).withAllErrors();
			let rejected = null, received = false;
			try {
				const response = await form.post(route("dapter.data", { source: props.source }), {
					onError: (errors) => {
						rejected = validationFailure(errors, 2);
					},
					onHttpException: (response) => {
						try {
							rejected = parseFailure(JSON.parse(response.data), 2);
							if (rejected?.kind === "cursor_expired" && (response.status !== 409 || state?.paging.mode !== "cursor")) rejected = protocolError();
						} catch {
							rejected = protocolError();
						}
						if (!rejected) rejected = protocolError();
						if ([
							401,
							403,
							404
						].includes(response.status)) rejected = {
							kind: "access",
							code: "access_denied",
							message: "Access to this data source was denied."
						};
					}
				});
				if (!mounted || active !== generation) return;
				if (rejected) throw rejected;
				received = true;
				const page = parsePageV2(response);
				if (props.paging !== "source" && page.paging.mode !== "offset") throw new Error("Source paging requires opt-in");
				if (state && state.paging.mode !== page.requested.paging.mode) throw new Error("Mismatched requested mode");
				if (page.requested.paging.mode === "cursor" && page.requested.paging.cursor !== (state?.paging.mode === "cursor" ? state.paging.cursor : null)) throw new Error("Mismatched incoming cursor");
				if (state?.paging.mode === "simple" && (page.requested.paging.mode !== "simple" || page.requested.paging.current !== state.paging.current)) throw new Error("Mismatched simple ordinal");
				const result = acceptWindow(session.value.accepted, decode$1(page, props), reason === "append", infiniteEnabled);
				if (!mounted || active !== generation) return;
				work = {
					kind: "publishing",
					generation: active
				};
				session.value = {
					accepted: result,
					definitionContext: {
						definition: result.definition,
						pages: result.page.paging.pages
					},
					requested: result.page.requested,
					status: "success",
					error: null
				};
				if (!mounted || active !== generation) {
					resolve({ status: "superseded" });
					return;
				}
				const completedReason = pending?.promise === promise ? pending.reason : reason;
				const completedExplicit = pending?.promise === promise ? pending.explicit : explicit;
				listen(page.metadata.channel, completedExplicit, [
					"reload",
					"reset",
					"initial"
				].includes(completedReason));
				if (!mounted || active !== generation) {
					resolve({ status: "superseded" });
					return;
				}
				if (completedExplicit) pollingPaused = false;
				const publication = browser.publish(completedReason === "reset" ? null : result.window?.start ?? page.requested, completedReason, () => mounted && active === generation);
				if (reason === "append" || settledOutcome) {
					const settled = await settlePublication(publication, active, epoch);
					if (!mounted || active !== generation) {
						resolve({ status: "superseded" });
						return;
					}
					appendOperation = null;
					if (settled) {
						infiniteState.value = nextPosition(result) ? "idle" : "exhausted";
						observedReady.value = true;
						resolve({ status: "success" });
					} else resolve({
						status: "failure",
						error: protocolError()
					});
				} else {
					resolve({ status: "success" });
					settlePublication(publication, active, epoch).then((settled) => {
						if (settled && mounted && active === generation && infiniteEnabled) {
							infiniteState.value = nextPosition(session.value.accepted ?? result) ? "idle" : "exhausted";
							observedReady.value = true;
						}
					});
				}
			} catch (cause) {
				if (!mounted || active !== generation) return;
				const failure = rejected ?? (!received && cause instanceof HttpNetworkError ? {
					kind: "network",
					code: "request_failed",
					message: "The data source could not be reached."
				} : protocolError());
				discardBuffer();
				pollingPaused = true;
				cancelPoll();
				work = { kind: "idle" };
				const clear = failure.kind === "access" || failure.kind === "protocol" || failure.code === "invalid_cursor" || failure.kind === "cursor_expired" && (!session.value.accepted || !sameContext(session.value.accepted.page.requested, state));
				session.value = {
					...session.value,
					accepted: clear ? null : session.value.accepted,
					definitionContext: clear ? null : session.value.definitionContext,
					error: Object.freeze(failure),
					status: "error"
				};
				if (infiniteEnabled) {
					restartInfinite = failure.kind === "cursor_expired" || failure.code === "invalid_cursor";
					infiniteState.value = "paused";
					appendOperation = null;
				}
				if (!session.value.accepted) clearLive(infiniteEnabled ? "window" : "context");
				resolve({
					status: "failure",
					error: failure
				});
			} finally {
				if (active === generation) {
					pending = null;
					if (work.kind === "idle") schedulePoll();
				}
			}
		}
		function dispatch() {
			Promise.resolve().then(run);
		}
		pending = {
			key,
			explicit,
			reason,
			promise,
			settle: resolve,
			cancel: () => form?.cancel(),
			timer: null,
			flush() {
				if (this.timer !== null) {
					clearTimeout(this.timer);
					this.timer = null;
					dispatch();
				}
			}
		};
		if (delay > 0) pending.timer = setTimeout(() => {
			if (pending?.promise === promise) pending.timer = null;
			dispatch();
		}, delay);
		else dispatch();
		return promise;
	}
	function apply(command, captured = epoch) {
		if (!mounted || captured !== epoch) return superseded();
		const result = session.value.accepted, current = session.value.requested;
		if (infiniteEnabled && "options" in command && command.options?.page === "preserve") return invalid();
		const definition = result?.definition ?? (infiniteEnabled ? session.value.definitionContext?.definition ?? null : null);
		if (!definition || !current) return invalid();
		if (command.kind === "globalSearch" && !definition.global.search || command.kind === "globalFilter" && !definition.global.filters?.[command.id] || command.kind === "preset" && !definition.global.order || command.kind === "grouping" && definition.global.searchMatch.allowed.length < 2 || command.kind === "search" && !definition.fields[command.field]?.search || command.kind === "order" && !definition.fields[command.field]?.order || command.kind === "filter" && !definition.fields[command.field]?.filters?.[command.id]) return invalid();
		try {
			const next = reduceRequested(current, command, definition, result?.page.paging.pages ?? session.value.definitionContext?.pages ?? null);
			if (next === current || stateKey(next) === stateKey(current)) {
				if (infiniteEnabled && (command.kind === "page" || command.kind === "cursor") && (result?.window?.consumed.length ?? 0) > 1) return submit(next, true, 0, "page");
				if (pending) {
					if (command.kind !== "search" && command.kind !== "globalSearch") pending.flush();
					return pending.promise;
				}
				return success();
			}
			return submit(next, false, (command.kind === "search" || command.kind === "globalSearch") && !command.clear ? props.searchDebounce ?? 0 : 0, command.kind === "globalSearch" ? "search" : [
				"filter",
				"order",
				"globalFilter",
				"preset",
				"grouping"
			].includes(command.kind) ? "criteria" : command.kind === "replace" || command.kind === "page" || command.kind === "size" || command.kind === "search" ? command.kind : "criteria");
		} catch {
			return invalid();
		}
	}
	function sameContext(left, right) {
		if (!right) return false;
		return stateKey({
			...left,
			paging: {
				mode: left.paging.mode,
				per_page: left.paging.per_page
			}
		}) === stateKey({
			...right,
			paging: {
				mode: right.paging.mode,
				per_page: right.paging.per_page
			}
		});
	}
	function first() {
		const current = session.value.requested;
		if (!current) return props.paging === "source" ? invalid() : success();
		if (current.paging.mode === "cursor") return submit(freeze({
			...current,
			paging: {
				...current.paging,
				cursor: null
			}
		}), false, 0, "page");
		if (!session.value.accepted) return submit(freeze({
			...current,
			paging: {
				...current.paging,
				current: 1
			}
		}), false, 0, "page");
		return goToPage(1);
	}
	function move(forward) {
		const result = session.value.accepted, current = session.value.requested;
		if (!result || !current) return props.paging === "source" ? invalid() : success();
		if (current.paging.mode === "cursor") {
			if (result.page.paging.mode !== "cursor" || !sameContext(result.page.requested, current)) return invalid();
			const token = forward ? result.page.paging.next_cursor : result.page.paging.previous_cursor;
			if (token === null) return success();
			return apply({
				kind: "cursor",
				value: token
			});
		}
		const position = current.paging.current;
		if (forward && result.page.paging.mode === "offset" && position >= result.page.paging.pages) return success();
		if (forward ? result.page.paging.has_next : position > 1) return goToPage(position + (forward ? 1 : -1));
		return success();
	}
	const reload = () => submit(infiniteEnabled ? firstPosition(session.value.requested) : session.value.requested, true, 0, "reload"), resetState = () => submit(null, true, 0, "reset"), replaceState = (value) => apply({
		kind: "replace",
		value
	});
	const goToPage = (value) => apply({
		kind: "page",
		value
	}), setValue = (value) => apply({
		kind: "size",
		value
	});
	function append(retry = false) {
		if (!mounted) return superseded();
		if (appendOperation) return appendOperation.promise;
		if (pending || work.kind !== "idle") return superseded();
		if (infiniteState.value === "paused") {
			if (!retry) return superseded();
			if (restartInfinite || !session.value.accepted) return submit(firstPosition(session.value.requested), true, 0, "reload", true, true);
		} else if (retry) return success();
		const result = session.value.accepted;
		if (!result) return superseded();
		const next = nextPosition(result);
		if (!next) {
			infiniteState.value = "exhausted";
			return success();
		}
		return submit(next, true, 0, "append");
	}
	const stopObservation = props.infinite && typeof props.infinite === "object" ? observeInfinite(props.infinite, {
		ready: () => observedReady.value,
		status: () => infiniteState.value,
		append: () => append()
	}) : () => {};
	const state = computed(() => {
		const result = session.value.accepted;
		const definition = result?.definition ?? (infiniteEnabled ? session.value.definitionContext?.definition ?? null : null);
		const captured = epoch;
		const own = (action) => () => mounted && captured === epoch ? action() : superseded();
		const controls = {
			loading: session.value.status === "loading",
			empty: !!result && !result.page.data.length,
			has_previous: result?.page.paging.has_previous ?? false,
			has_next: result?.page.paging.has_next ?? false,
			per_page: Object.freeze({
				value: result?.page.paging.per_page ?? null,
				options: result?.definition.paging.per_page_options ?? Object.freeze([]),
				setValue: (value) => mounted && captured === epoch ? setValue(value) : superseded()
			}),
			goToFirst: own(first),
			goToPrevious: own(() => move(false)),
			goToNext: own(() => move(true)),
			reload: own(reload)
		};
		const page = result?.page.paging;
		let paging;
		if (!page && props.paging === "source") paging = Object.freeze({
			...controls,
			mode: null,
			empty: false,
			has_previous: false,
			has_next: false,
			per_page: Object.freeze({
				value: null,
				options: Object.freeze([]),
				setValue: controls.per_page.setValue
			}),
			current: null,
			pages: null,
			counts: null,
			total: null,
			filtered: null,
			previous_cursor: null,
			next_cursor: null,
			goToPage: null,
			goToLast: null
		});
		else if (!page || page.mode === "offset") paging = Object.freeze({
			...controls,
			mode: "offset",
			current: page?.current ?? null,
			pages: page?.pages ?? null,
			total: page?.total ?? null,
			filtered: page?.filtered ?? null,
			goToPage: (value) => mounted && captured === epoch ? goToPage(value) : superseded(),
			goToLast: own(() => {
				const accepted = session.value.accepted?.page.paging;
				return accepted?.mode === "offset" ? goToPage(accepted.pages) : success();
			})
		});
		else {
			const count = page.total === null || page.filtered === null ? {
				counts: "unknown",
				total: null,
				filtered: null
			} : {
				counts: "exact",
				total: page.total,
				filtered: page.filtered
			};
			const shared = {
				...controls,
				per_page: Object.freeze({
					...controls.per_page,
					value: page.per_page
				}),
				...count,
				pages: null,
				goToPage: null,
				goToLast: null
			};
			paging = page.mode === "simple" ? Object.freeze({
				...shared,
				mode: "simple",
				current: page.current,
				previous_cursor: null,
				next_cursor: null
			}) : Object.freeze({
				...shared,
				mode: "cursor",
				current: null,
				previous_cursor: page.previous_cursor,
				next_cursor: page.next_cursor
			});
		}
		const common = {
			status: session.value.status,
			error: session.value.error,
			integrationError: integrationError.value,
			infinite: infiniteEnabled ? Object.freeze({
				status: infiniteState.value,
				append: own(() => append()),
				retry: own(() => append(true))
			}) : null,
			resetContext: own(resetContext),
			restartPolling: () => {
				if (mounted && captured === epoch) restartPolling();
			},
			requested: session.value.requested,
			accepted: result?.page.state ?? null,
			paging,
			definition,
			capabilities: definition,
			metadata: result?.page.metadata ?? null,
			fields: definition ? project(definition, session.value.requested, (command) => apply(command, captured)) : null,
			global: definition ? projectGlobal(definition, session.value.requested, (command) => apply(command, captured)) : null,
			setSearchMatch: definition && definition.global.searchMatch.allowed.length > 1 ? (value, options) => apply({
				kind: "grouping",
				value,
				...options ? { options } : {}
			}, captured) : null,
			actions: actions.controls(),
			replaceState: (value) => mounted && captured === epoch ? replaceState(value) : superseded(),
			resetState: own(resetState)
		};
		return result ? Object.freeze({
			...common,
			ready: true,
			data: result.data
		}) : Object.freeze({
			...common,
			ready: false,
			data: Object.freeze([])
		});
	});
	function resetContext() {
		epoch++;
		actions.reset();
		invalidate();
		clearLive();
		session.value = {
			accepted: null,
			definitionContext: null,
			requested: null,
			error: null,
			status: "idle"
		};
		pollingPaused = false;
		const clearing = browser.reset();
		return mounted ? submit(null, true, 0, "reset") : clearing.then(() => ({ status: "superseded" }));
	}
	watch(() => [
		props.source,
		props.decodeRow,
		props.decodeDefinition,
		props.actionDefinitions
	], () => {
		epoch++;
		actions.reset();
		clearLive();
		browser.dispose();
		invalidate();
		session.value = {
			accepted: null,
			definitionContext: null,
			requested: null,
			error: null,
			status: "idle"
		};
		browser = makeBrowser();
		if (mounted) initial();
	}, { flush: "sync" });
	return {
		state,
		start() {
			if (mounted) return;
			mounted = true;
			if (polling) {
				if (typeof document !== "undefined") document.addEventListener("visibilitychange", availability);
				if (typeof window !== "undefined") {
					window.addEventListener("online", availability);
					window.addEventListener("offline", availability);
				}
			}
			initial();
		},
		dispose() {
			stopObservation();
			mounted = false;
			if (polling) {
				if (typeof document !== "undefined") document.removeEventListener("visibilitychange", availability);
				if (typeof window !== "undefined") {
					window.removeEventListener("online", availability);
					window.removeEventListener("offline", availability);
				}
			}
			epoch++;
			actions.reset();
			clearLive();
			browser.dispose();
			invalidate();
			session.value = {
				accepted: null,
				definitionContext: null,
				requested: null,
				error: null,
				status: "idle"
			};
		}
	};
}
// resources/js/adapter.ts
function decode(data, props) {
	return freeze(data.map((row) => props.decodeRow ? props.decodeRow(row) : row));
}
function createLegacyController(props) {
	const accepted = shallowRef(null);
	const requested = shallowRef(null);
	const error = shallowRef(null);
	const status = shallowRef("idle");
	let generation = 0, epoch = 0;
	let mounted = false;
	let pending = null;
	const actions = createActionController({
		metadata: () => accepted.value?.page.actions ?? null,
		definitions: () => props.actionDefinitions,
		active: () => mounted,
		epoch: () => epoch
	});
	const success = () => Promise.resolve({ status: "success" });
	function rejectLocal() {
		return Promise.resolve({
			status: "failure",
			error: {
				kind: "validation",
				code: "invalid_paging",
				message: "Choose an available page and page size."
			}
		});
	}
	function invalidate() {
		generation++;
		const previous = pending;
		pending = null;
		previous?.cancel();
	}
	function submit(state, force = false) {
		if (!mounted) return Promise.resolve({ status: "superseded" });
		const key = JSON.stringify(state);
		if (!force && pending?.key === key) return pending.promise;
		if (!force && !pending && status.value === "success" && JSON.stringify(requested.value) === key) return success();
		invalidate();
		const active = generation;
		requested.value = freeze(state);
		status.value = "loading";
		error.value = null;
		const form = useHttp(state ? {
			version: 1,
			state
		} : { version: 1 }).withAllErrors();
		let rejected = null;
		let received = false;
		const promise = Promise.resolve().then(async () => {
			if (active !== generation || !mounted) return { status: "superseded" };
			try {
				const response = await form.post(route("dapter.data", { source: props.source }), {
					onError: (errors) => {
						rejected = validationFailure(errors);
					},
					onHttpException: (response) => {
						try {
							rejected = parseFailure(JSON.parse(response.data));
						} catch {
							rejected = protocolError();
						}
						if (!rejected) rejected = protocolError();
						if ([
							401,
							403,
							404
						].includes(response.status)) rejected = {
							kind: "access",
							code: "access_denied",
							message: "Access to this data source was denied."
						};
					}
				});
				if (active !== generation || !mounted) return { status: "superseded" };
				if (rejected) throw rejected;
				received = true;
				const page = parsePage(response);
				const data = decode(page.data, props);
				if (active !== generation || !mounted) return { status: "superseded" };
				accepted.value = {
					page,
					data
				};
				requested.value = page.requested;
				status.value = "success";
				error.value = null;
				return { status: "success" };
			} catch (cause) {
				if (active !== generation || !mounted) return { status: "superseded" };
				const failure = rejected ?? (!received && cause instanceof HttpNetworkError ? {
					kind: "network",
					code: "request_failed",
					message: "The data source could not be reached."
				} : protocolError());
				if (failure.kind === "access" || failure.kind === "protocol") accepted.value = null;
				error.value = Object.freeze(failure);
				status.value = "error";
				return {
					status: "failure",
					error: failure
				};
			} finally {
				if (active === generation) pending = null;
			}
		});
		pending = {
			key,
			cancel: () => form.cancel(),
			promise
		};
		return promise;
	}
	function goToPage(value) {
		const page = accepted.value?.page;
		if (!page || !Number.isSafeInteger(value) || value < 1 || value > page.paging.pages) return rejectLocal();
		return submit(intent(value, requested.value?.paging.per_page ?? page.paging.per_page));
	}
	function setValue(value) {
		const page = accepted.value?.page;
		if (!page || !page.options.includes(value)) return rejectLocal();
		if ((requested.value?.paging.per_page ?? page.paging.per_page) === value) return submit(requested.value ?? page.requested);
		return submit(intent(1, value));
	}
	const reload = () => submit(requested.value, true);
	const goToFirst = () => accepted.value ? goToPage(1) : success();
	const goToPrevious = () => (requested.value?.paging.current ?? 1) > 1 ? goToPage((requested.value?.paging.current ?? 1) - 1) : success();
	const goToNext = () => accepted.value && (requested.value?.paging.current ?? 1) < accepted.value.page.paging.pages ? goToPage((requested.value?.paging.current ?? 1) + 1) : success();
	const goToLast = () => accepted.value ? goToPage(accepted.value.page.paging.pages) : success();
	const slot = computed(() => {
		const result = accepted.value;
		const captured = epoch;
		const own = (command) => (...args) => mounted && captured === epoch ? command(...args) : Promise.resolve({ status: "superseded" });
		const paging = {
			mode: "offset",
			loading: status.value === "loading",
			empty: !!result && result.page.data.length === 0,
			current: result?.page.paging.current ?? null,
			pages: result?.page.paging.pages ?? null,
			total: result?.page.paging.total ?? null,
			filtered: result?.page.paging.filtered ?? null,
			has_previous: result?.page.paging.has_previous ?? false,
			has_next: result?.page.paging.has_next ?? false,
			per_page: {
				value: result?.page.paging.per_page ?? null,
				options: result?.page.options ?? Object.freeze([]),
				setValue: own(setValue)
			},
			goToPage: own(goToPage),
			goToFirst: own(goToFirst),
			goToPrevious: own(goToPrevious),
			goToNext: own(goToNext),
			goToLast: own(goToLast),
			reload: own(reload)
		};
		Object.freeze(paging.per_page);
		Object.freeze(paging);
		const common = {
			status: status.value,
			error: error.value,
			integrationError: null,
			infinite: null,
			resetContext: own(resetContext),
			restartPolling: () => {},
			requested: requested.value,
			accepted: result?.page.state ?? null,
			paging,
			definition: null,
			capabilities: null,
			metadata: null,
			fields: null,
			global: null,
			setSearchMatch: null,
			actions: actions.controls(),
			replaceState: (_value) => rejectLocal(),
			resetState: own(() => submit(null, true))
		};
		return result ? Object.freeze({
			...common,
			ready: true,
			data: result.data
		}) : Object.freeze({
			...common,
			ready: false,
			data: Object.freeze([])
		});
	});
	function resetContext() {
		epoch++;
		actions.reset();
		invalidate();
		accepted.value = null;
		requested.value = null;
		error.value = null;
		status.value = "idle";
		return mounted ? submit(null, true) : Promise.resolve({ status: "superseded" });
	}
	watch(() => [
		props.source,
		props.decodeRow,
		props.actionDefinitions
	], () => {
		epoch++;
		actions.reset();
		invalidate();
		accepted.value = null;
		requested.value = null;
		error.value = null;
		status.value = "idle";
		if (mounted) submit(null);
	}, { flush: "sync" });
	return {
		state: slot,
		start() {
			if (mounted) return;
			mounted = true;
			submit(null);
		},
		dispose() {
			mounted = false;
			epoch++;
			actions.reset();
			invalidate();
			accepted.value = null;
			requested.value = null;
			error.value = null;
			status.value = "idle";
		}
	};
}
function createController(props, identity) {
	if (props.protocol !== 2 && (props.echo !== void 0 || props.polling !== void 0 && props.polling !== false)) throw new Error("Echo and polling require protocol 2.");
	if (props.infinite !== void 0 && props.infinite !== false && props.protocol !== 2) throw new Error("Infinite loading requires protocol 2.");
	if (props.infinite !== void 0 && typeof props.infinite !== "boolean") {
		const option = props.infinite;
		if (!option || typeof option !== "object" || !("root" in option) || !("sentinel" in option) || Object.keys(option).some((key) => ![
			"root",
			"sentinel",
			"axis"
		].includes(key)) || option.axis !== void 0 && option.axis !== "y" && option.axis !== "x") throw new Error("Infinite observation requires root, sentinel, and an x or y axis.");
	}
	if (props.paging !== void 0 && props.paging !== "offset" && props.paging !== "source") throw new Error("Choose offset or source paging.");
	if (props.paging === "source" && props.protocol !== 2) throw new Error("Source paging requires protocol 2.");
	return props.protocol === 2 ? createV2Controller(props, identity) : createLegacyController(props);
}
// resources/js/useDataAdapter.ts
const optionKeys = [
	"decodeRow",
	"protocol",
	"paging",
	"decodeDefinition",
	"actionDefinitions",
	"searchDebounce",
	"echo",
	"polling",
	"url",
	"scrollContainer",
	"infinite"
];
const contextKey = Symbol("Dapter owner");
const contexts = /* @__PURE__ */ new WeakMap();
const handles = /* @__PURE__ */ new WeakMap();
function context() {
	const owner = getCurrentInstance();
	if (!owner) throw new Error("useDataAdapter must be called synchronously during Vue setup.");
	const existing = contexts.get(owner);
	if (existing) return existing;
	const created = {
		parent: inject(contextKey, void 0),
		entries: /* @__PURE__ */ new Map()
	};
	contexts.set(owner, created);
	provide(contextKey, created);
	onBeforeUnmount(() => contexts.delete(owner));
	return created;
}
function facade(state) {
	return new Proxy({ ...state.value }, {
		get: (_target, key) => Reflect.get(state.value, key),
		set: () => false,
		deleteProperty: () => false,
		defineProperty: () => false
	});
}
function create(input, options, props) {
	const ownerProps = props ?? {
		get source() {
			return toValue(input);
		},
		...options
	};
	if (options.id !== void 0 && options.id.trim() === "") throw new Error("An adapter id must not be empty.");
	const identity = options.id ?? useId();
	const scope = effectScope(true);
	const controller = scope.run(() => createController(ownerProps, identity));
	if (!controller) throw new Error("The adapter owner scope could not be created.");
	const handle = facade(controller.state);
	let disposed = false;
	const entry = {
		input,
		id: options.id,
		options: ownerProps,
		handle,
		start() {
			if (!disposed) controller.start();
		},
		dispose() {
			if (disposed) return;
			disposed = true;
			controller.dispose();
			scope.stop();
		}
	};
	handles.set(handle, entry);
	onBeforeUnmount(entry.dispose);
	return entry;
}
function attach(entry) {
	onMounted(entry.start);
	return entry.handle;
}
function compatible(entry, input, options) {
	if (entry.options.paging === "source" && options.paging !== "source" || entry.input !== input || optionKeys.some((key) => options[key] !== void 0 && entry.options[key] !== options[key])) throw new Error("An adapter acquisition conflicts with its owner source or decoder.");
}
function useDataAdapter(source, options = {}) {
	const local = context();
	if (typeof source === "object" && source !== null && !isRef(source)) {
		const entry = handles.get(source);
		if (!entry) throw new Error("Unknown adapter handle.");
		return attach(entry);
	}
	if (typeof source !== "string" && typeof source !== "function" && !isRef(source)) throw new Error("Use a Dapter handle or a source string, Ref, or getter.");
	if (typeof source !== "string" && !options.id) throw new Error("A reactive adapter source requires a stable id.");
	if (isSourceGetter(source)) return acquire(source, options, local);
	if (typeof source !== "string" && !isRef(source)) throw new Error("Invalid source");
	return acquire(source, options, local);
}
function isSourceGetter(source) {
	return typeof source === "function" && typeof source() === "string";
}
function acquire(source, options, local) {
	const key = options.id === void 0 ? `source:${toValue(source)}` : `id:${options.id}`;
	let current = local;
	while (current) {
		const boundary = current.boundary;
		const entry = current.entries.get(key) ?? (boundary && (options.id === void 0 ? toValue(boundary.input) === toValue(source) : options.id === boundary.id) ? boundary : void 0);
		if (entry) {
			if (entry === boundary) {
				if (entry.options.paging === "source" && options.paging !== "source" || entry.input !== source && !(typeof source === "string" && toValue(entry.input) === source) || optionKeys.some((key) => options[key] !== void 0 && entry.options[key] !== options[key])) throw new Error("An adapter acquisition conflicts with its owner decoder.");
			} else compatible(entry, source, options);
			return attach(entry);
		}
		current = current.parent;
	}
	const entry = create(source, options);
	local.entries.set(key, entry);
	return attach(entry);
}
function componentAdapter(props) {
	const attached = shallowRef(true);
	const local = context();
	let handle;
	if (props.adapter !== void 0) {
		if (props.source !== void 0 || props.id !== void 0 || optionKeys.some((key) => props[key] !== void 0)) throw new Error("A bound adapter cannot override its source, id, or decoder.");
		handle = useDataAdapter(props.adapter);
		local.boundary = handles.get(handle);
		watch(() => [
			props.adapter,
			props.source,
			props.id,
			...optionKeys.map((key) => props[key])
		], () => {
			if (props.adapter === handle && props.source === void 0 && props.id === void 0 && optionKeys.every((key) => props[key] === void 0)) return;
			attached.value = false;
			local.boundary = void 0;
			throw new Error("A bound adapter handle cannot change. Remount the view with a stable key.");
		}, { flush: "sync" });
	} else {
		const ownerOptions = {
			infinite: props.infinite,
			protocol: props.protocol,
			paging: props.paging,
			searchDebounce: props.searchDebounce,
			echo: props.echo,
			polling: props.polling,
			url: props.url
		};
		const entry = create(() => props.source, props, props);
		handle = entry.handle;
		local.boundary = entry;
		onMounted(entry.start);
		watch(() => [
			props.adapter,
			props.id,
			props.infinite,
			props.protocol,
			props.paging,
			props.searchDebounce,
			props.echo,
			props.polling,
			props.url
		], () => {
			if (props.adapter === void 0 && props.id === entry.id && Object.entries(ownerOptions).every(([key, value]) => Reflect.get(props, key) === value)) return;
			attached.value = false;
			local.boundary = void 0;
			entry.dispose();
			throw new Error(props.adapter !== void 0 ? "An adapter ownership mode cannot change. Remount the view with a stable key." : props.id !== entry.id ? "An adapter id cannot change. Remount the view with a stable key." : "An adapter creation option cannot change. Remount the view with a stable key.");
		}, { flush: "sync" });
	}
	return computed(() => attached.value ? handle : null);
}
// resources/js/DataAdapter.vue
var DataAdapter_default = /* @__PURE__ */ defineComponent({
	__name: "DataAdapter",
	props: {
		source: {
			type: null,
			required: false
		},
		id: {
			type: null,
			required: false
		},
		decodeRow: {
			type: Function,
			required: false
		},
		decodeDefinition: {
			type: Function,
			required: false
		},
		actionDefinitions: {
			type: Object,
			required: false
		},
		searchDebounce: {
			type: null,
			required: false
		},
		echo: {
			type: null,
			required: false
		},
		polling: {
			type: [Boolean, Object],
			required: false,
			skipCheck: true,
			default: void 0
		},
		url: {
			type: Boolean,
			required: false,
			skipCheck: true,
			default: void 0
		},
		scrollContainer: {
			type: null,
			required: false
		},
		infinite: {
			type: [Boolean, Object],
			required: false,
			skipCheck: true,
			default: void 0
		},
		adapter: {
			type: null,
			required: false
		},
		protocol: {
			type: null,
			required: false
		},
		paging: {
			type: null,
			required: false
		}
	},
	setup(__props) {
		const state = componentAdapter(__props);
		return (_ctx, _cache) => {
			return unref(state) ? renderSlot(_ctx.$slots, "default", normalizeProps(guardReactiveProps(unref(state))), void 0, void 0, 0) : createCommentVNode("v-if", true);
		};
	}
});
// resources/js/index.ts
const version = version$1;
export { DataAdapter_default as DataAdapter, defineActions, useDataAdapter, version };

//# sourceMappingURL=index.js.map