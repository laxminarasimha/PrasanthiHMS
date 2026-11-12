export type User = {
  id: number
  name: string
  email: string
  role: string
  role_label: string
  can_manage_staff: boolean
  branch: { id: number; name: string; city: string | null } | null
}

export type Staff = {
  id: number
  name: string
  email: string
  role: string
  gender: string | null
  phone: string | null
  department: string | null
  signature_text: string | null
  is_active: boolean
}

export type RoleOption = { value: string; label: string }

export type Patient = {
  id: number
  uhid: string
  name: string
  gender: string
  date_of_birth: string
  dob_estimated: boolean
  age: number
  mobile: string
  mobile_alt: string | null
  email: string | null
  blood_group: string | null
  preferred_language: string | null
  address: string | null
  city: string | null
  pincode: string | null
  care_of: string | null
  occupation: string | null
  referred_by: string | null
  channel: string | null
  tag: string | null
  existing_id: string | null
  created_at: string
}

export type Doctor = { id: number; name: string; department: string | null }

export type Service = {
  id: number
  code: string | null
  name: string
  type: 'appointment' | 'other'
  price: number
  gst_percent: number
  doctor_id: number | null
  doctor: { id: number; name: string } | null
  is_active: boolean
}

export type AppointmentStatus = 'booked' | 'arrived' | 'ongoing' | 'reviewed' | 'cancelled'

export type Appointment = {
  id: number
  scheduled_at: string
  duration_minutes: number
  status: AppointmentStatus
  notes: string | null
  patient: Pick<Patient, 'id' | 'uhid' | 'name' | 'gender' | 'age' | 'mobile'>
  doctor: { id: number; name: string }
  service: { id: number; name: string } | null
  bills: { id: number; bill_no: string; total: number; paid: number; due: number }[]
}

export type BillItem = {
  id: number
  description: string
  quantity: number
  unit_price: number
  gst_percent: number
  amount: number
  tax: number
}

export type Payment = {
  id: number
  type: 'payment' | 'refund'
  amount: number
  mode: string
  reference: string | null
  created_at: string
  receiver: { id: number; name: string } | null
}

export type Bill = {
  id: number
  bill_no: string
  subtotal: number
  tax: number
  discount: number
  total: number
  paid: number
  due: number
  created_at: string
  items: BillItem[]
  payments: Payment[]
  patient: Pick<Patient, 'id' | 'uhid' | 'name' | 'gender' | 'age' | 'mobile' | 'address' | 'city'>
  appointment: { id: number; scheduled_at: string; doctor: { id: number; name: string } } | null
}
