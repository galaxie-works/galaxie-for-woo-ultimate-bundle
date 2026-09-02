import * as React from 'react'

import { cn } from '@/lib/cn'

/**
 * Six single-digit boxes acting as one code. Controlled: `value` is the joined
 * digit string, `onChange` receives the updated joined string. Handles
 * auto-advance, backspace-to-previous, and pasting a full 6-digit code into
 * any cell.
 */
interface OtpInputProps {
  value: string
  onChange: (value: string) => void
  length?: number
  disabled?: boolean
  autoFocus?: boolean
}

function OtpInput({ value, onChange, length = 6, disabled, autoFocus }: OtpInputProps) {
  const refs = React.useRef<Array<HTMLInputElement | null>>([])
  const digits = React.useMemo(() => {
    const arr = value.split('').slice(0, length)
    while (arr.length < length) arr.push('')
    return arr
  }, [value, length])

  function setDigit(index: number, digit: string) {
    const next = digits.slice()
    next[index] = digit
    onChange(next.join(''))
  }

  function handleChange(index: number, raw: string) {
    const digit = raw.replace(/\D/g, '').slice(-1)
    setDigit(index, digit)
    if (digit && index < length - 1) {
      refs.current[index + 1]?.focus()
    }
  }

  function handleKeyDown(index: number, e: React.KeyboardEvent<HTMLInputElement>) {
    if (e.key === 'Backspace' && !digits[index] && index > 0) {
      refs.current[index - 1]?.focus()
    }
  }

  function handlePaste(index: number, e: React.ClipboardEvent<HTMLInputElement>) {
    const pasted = e.clipboardData.getData('text').replace(/\D/g, '')
    if (!pasted) return
    e.preventDefault()
    const next = digits.slice()
    for (let i = 0; i < pasted.length && index + i < length; i++) {
      next[index + i] = pasted[i]
    }
    onChange(next.join(''))
    const lastIndex = Math.min(index + pasted.length, length - 1)
    refs.current[lastIndex]?.focus()
  }

  return (
    <div className="flex gap-2">
      {digits.map((digit, i) => (
        <input
          key={i}
          ref={(el) => {
            refs.current[i] = el
          }}
          type="text"
          inputMode="numeric"
          autoComplete={0 === i ? 'one-time-code' : 'off'}
          maxLength={1}
          value={digit}
          disabled={disabled}
          autoFocus={autoFocus && 0 === i}
          onChange={(e) => handleChange(i, e.target.value)}
          onKeyDown={(e) => handleKeyDown(i, e)}
          onPaste={(e) => handlePaste(i, e)}
          className={cn(
            'h-12 w-10 rounded-md border border-input bg-transparent text-center text-lg font-medium shadow-xs outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50'
          )}
        />
      ))}
    </div>
  )
}

export { OtpInput }
