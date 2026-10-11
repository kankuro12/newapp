import type { Item } from './types';
import type { Config, Measurement } from '../pages/Pos';

/** Measurement helpers shared by Quick POS and Full POS. */
export function enabled(item: Item, config: Config): string[] {
  const family = config.units[item.pos_unit || 'unit']?.[0] || 'count';
  return (item.pos_methods || config.methods).filter(
    (mode) =>
      ['quantity', 'amount'].includes(mode) ||
      (mode === 'pack' && !!item.pos_custom_units?.length) ||
      mode === family,
  );
}
export function initialMeasurement(item: Item, config: Config): Measurement {
  const available = enabled(item, config);
  const family = config.units[item.pos_unit || 'unit']?.[0];
  const mode =
    family && ['length', 'area', 'volume'].includes(family) && available.includes(family)
      ? family
      : available[0] || 'quantity';
  return {
    mode,
    value: '1',
    unit: item.pos_unit || 'unit',
    length: '1',
    width: '1',
    thickness: '1',
    length_unit: 'ft',
    width_unit: 'ft',
    thickness_unit: 'in',
    pieces: '1',
    custom_index: 0,
  };
}
export function wireMeasurement(m: Measurement): Measurement {
  if (m.barcode)
    return {
      mode: m.mode,
      value: m.value,
      unit: m.unit,
      barcode: m.barcode,
      barcode_fingerprint: m.barcode_fingerprint,
    };
  if (['quantity', 'amount', 'pack'].includes(m.mode))
    return {
      mode: m.mode,
      value: m.value,
      ...(m.mode === 'quantity'
        ? { unit: m.unit }
        : m.mode === 'pack'
          ? { custom_index: m.custom_index }
          : {}),
    };
  return {
    mode: m.mode,
    length: m.length,
    length_unit: m.length_unit,
    pieces: m.pieces,
    ...(['area', 'volume'].includes(m.mode) ? { width: m.width, width_unit: m.width_unit } : {}),
    ...(m.mode === 'volume' ? { thickness: m.thickness, thickness_unit: m.thickness_unit } : {}),
  };
}
