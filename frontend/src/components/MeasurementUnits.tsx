import { useWorkspace } from '../lib/context';
import { measurementUnitGroups, measurementUnitLabel } from '../lib/measurement';

export default function MeasurementUnits() {
  const { t } = useWorkspace();
  return (
    <>
      {Object.entries(measurementUnitGroups).map(([group, units]) => (
        <optgroup key={group} label={t(group)}>
          {units.map((unit) => (
            <option key={unit} value={unit}>
              {t(measurementUnitLabel(unit))}
            </option>
          ))}
        </optgroup>
      ))}
    </>
  );
}
