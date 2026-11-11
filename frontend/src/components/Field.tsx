import type { InputHTMLAttributes, ReactNode } from 'react'

type FieldProps = {
  label: string
  error?: string
  required?: boolean
  children: ReactNode
}

/** A labelled form control with its validation message underneath. */
export function Field({ label, error, required, children }: FieldProps) {
  return (
    <label className="field">
      <span className="field-label">
        {label}
        {required && <span className="req"> *</span>}
      </span>
      {children}
      {error && <span className="field-error">{error}</span>}
    </label>
  )
}

type TextFieldProps = Omit<FieldProps, 'children'> &
  Omit<InputHTMLAttributes<HTMLInputElement>, 'onChange' | 'value'> & {
    value: string
    onChange: (value: string) => void
  }

export function TextField({ label, error, required, value, onChange, ...input }: TextFieldProps) {
  return (
    <Field label={label} error={error} required={required}>
      <input {...input} value={value} onChange={(e) => onChange(e.target.value)} aria-invalid={Boolean(error)} />
    </Field>
  )
}

type SelectFieldProps = Omit<FieldProps, 'children'> & {
  value: string
  onChange: (value: string) => void
  options: { value: string; label: string }[]
  placeholder?: string
}

export function SelectField({ label, error, required, value, onChange, options, placeholder }: SelectFieldProps) {
  return (
    <Field label={label} error={error} required={required}>
      <select value={value} onChange={(e) => onChange(e.target.value)} aria-invalid={Boolean(error)}>
        {placeholder !== undefined && <option value="">{placeholder}</option>}
        {options.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </select>
    </Field>
  )
}
