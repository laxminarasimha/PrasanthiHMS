export type User = {
  id: number
  name: string
  email: string
  role: string
  role_label: string
  can_manage_staff: boolean
  can_consult: boolean
  can_run_lab: boolean
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

export type Medicine = {
  id?: number
  medicine: string
  dose: string | null
  frequency: string | null
  timing: string | null
  duration: string | null
  instructions: string | null
}

export type Consultation = {
  id: number
  weight_kg: number | null
  height_cm: number | null
  bp_systolic: number | null
  bp_diastolic: number | null
  pulse: number | null
  temperature_f: number | null
  spo2: number | null
  complaints: string | null
  findings: string | null
  diagnosis: string | null
  tests_advised: string | null
  advice: string | null
  follow_up_on: string | null
  completed_at: string | null
  medicines: Medicine[]
  doctor?: { id: number; name: string }
}

export type VisitPatient = Pick<Patient, 'id' | 'uhid' | 'name' | 'gender' | 'age' | 'mobile' | 'blood_group'>

export type Visit = {
  appointment: { id: number; scheduled_at: string; status: AppointmentStatus; notes: string | null }
  patient: VisitPatient
  doctor: { id: number; name: string; department: string | null; signature_text: string | null }
  service: { id: number; name: string } | null
  consultation: Consultation | null
}

export type QueueEntry = {
  id: number
  scheduled_at: string
  arrived_at: string | null
  status: AppointmentStatus
  patient: VisitPatient
  doctor: { id: number; name: string }
  service: { id: number; name: string } | null
  consultation: { id: number; completed_at: string | null } | null
  visits: number
  last_visit: string | null
}

export type LabParameter = { id?: number; name: string; unit: string | null; reference_range: string | null; method: string | null }

export type LabCatalogueTest = {
  id: number
  code: string | null
  name: string
  department: string | null
  sample_type: string | null
  price: number
  print_order: number
  is_active: boolean
  parameters: LabParameter[]
}

export type LabResult = {
  id: number
  parameter: string
  unit: string | null
  reference_range: string | null
  method: string | null
  value: string | null
  flag: 'high' | 'low' | null
}

export type LabOrderItem = {
  id: number
  test_name: string
  department: string | null
  sample_type: string | null
  price: number
  remarks: string | null
  reported_at: string | null
  reporter?: { id: number; name: string; signature_text: string | null } | null
  results?: LabResult[]
}

export type LabOrder = {
  id: number
  order_no: string
  status: 'pending' | 'partial' | 'completed'
  ordered_at: string
  referred_by: string | null
  patient: Pick<Patient, 'id' | 'uhid' | 'name' | 'gender' | 'age' | 'mobile'>
  items: LabOrderItem[]
  bill: { id: number; bill_no: string; total: number; paid: number; due: number } | null
}
