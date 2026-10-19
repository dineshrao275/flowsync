/**
 * Asset condition vocabulary shared by the register, hand-over and return
 * forms. The stored values are a fixed set (`new`/`good`/`fair`/`poor`/
 * `damaged`); their labels live in the `hrms.assets.conditions.*` catalog so
 * the SPA never renders a raw column value.
 */
export const ASSET_CONDITIONS = ['new', 'good', 'fair', 'poor', 'damaged'];

export function assetConditionLabel(t, value) {
    if (!value) return '';

    return t(`hrms.assets.conditions.${value}`, { defaultValue: value });
}
