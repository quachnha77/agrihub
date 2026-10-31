import '@testing-library/jest-dom/vitest'
import { cleanup, configure } from '@testing-library/react'
import { afterEach, expect } from 'vitest'
import '../i18n/index.ts'
import { takeUnmockedCalls } from './utils.tsx'

// Chờ tối đa 4 giây cho findBy*/waitFor: khi cả bộ test chạy song song (hoặc trên máy CI chỉ 2 lõi) một màn hình đầy đủ dựng chậm hơn 1 giây mặc định.
// Test cố ý kiểm tra trạng thái "đang tải" thì tự giữ phản hồi bằng `after`, không dựa vào thời gian chờ ngắn.
configure({ asyncUtilTimeout: 4000 })

afterEach(() => {
  cleanup()
  // Gọi API chưa giả lập là lỗi của test: không để nó âm thầm thành "lỗi mạng" rồi vẫn xanh.
  expect(takeUnmockedCalls(), 'Có yêu cầu API chưa được giả lập trong test').toEqual([])
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
})
