import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach, expect } from 'vitest'
import '../i18n/index.ts'
import { takeUnmockedCalls } from './utils.tsx'

afterEach(() => {
  cleanup()
  // Gọi API chưa giả lập là lỗi của test: không để nó âm thầm thành "lỗi mạng" rồi vẫn xanh.
  expect(takeUnmockedCalls(), 'Có yêu cầu API chưa được giả lập trong test').toEqual([])
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
})
