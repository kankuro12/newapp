import { useState } from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';
import { Field } from './ui';
it('native time input event updates controlled appointment time before save', () => {
  function Form() {
    const [time, setTime] = useState('09:00');
    const [saved, setSaved] = useState('');
    return (
      <form
        onSubmit={(e) => {
          e.preventDefault();
          setSaved(time);
        }}
      >
        <Field
          label="Appointment time"
          type="time"
          value={time}
          onChange={(e) => setTime(e.target.value)}
        />
        <button>Save time</button>
        <output>{saved}</output>
      </form>
    );
  }
  render(<Form />);
  const input = screen.getByLabelText('Appointment time') as HTMLInputElement;
  input.value = '17:00';
  fireEvent.input(input);
  fireEvent.click(screen.getByRole('button', { name: 'Save time' }));
  expect(screen.getByRole('status')).toHaveTextContent('17:00');
});
