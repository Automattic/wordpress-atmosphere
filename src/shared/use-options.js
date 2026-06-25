/**
 * React hook returning the ATmosphere block options localized onto the
 * global window (namespace, default avatar, avatar setting).
 *
 * @return {Object} The options object.
 */
export function useOptions() {
	return window._atmosphereOptions || {};
}
