export type User = {
  id: number
  name: string
  email: string
  role: string
  role_label: string
  can_manage_staff: boolean
  can_consult: boolean
  can_run_lab: boolean
  can_run_pharmacy: boolean
  can_manage_setup: boolean
  can_front_desk: boolean
  can_ipd: boolean
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
  bills: { id: number; bill_no: string; total: number; paid: number; due: number; moved_to_ipd: number | null; lab_order: { id: number; order_no: string } | null }[]
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

export type PrescribedMedicine = {
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
  resp_rate: number | null
  quick_notes: string | null
  complaints: string | null
  history: string | null
  past_medication: string | null
  general_exam: Record<string, 'present' | 'absent'> | null
  general_exam_notes: string | null
  findings: string | null
  diagnosis: string | null
  assessment_plan: string | null
  refer_to: string | null
  tests_advised: string | null
  advice: string | null
  follow_up_on: string | null
  completed_at: string | null
  medicines: PrescribedMedicine[]
  doctor?: { id: number; name: string }
}

export type VisitPatient = Pick<Patient, 'id' | 'uhid' | 'name' | 'gender' | 'age' | 'mobile' | 'blood_group'> & { allergies: string | null }

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
  bill: { id: number; bill_no: string; total: number; paid: number; due: number; moved_to_ipd?: number | null } | null
}

export type Medicine = {
  id: number
  name: string
  type: string | null
  manufacturer: string | null
  category: string | null
  hsn_code: string | null
  gst_percent: number
  units_per_pack: number
  reorder_level: number
  is_active: boolean
  units_in_stock: number
}

export type Stockist = { id: number; name: string; phone: string | null; gstin: string | null; address: string | null }

export type StockBatch = {
  id: number
  medicine_id: number
  batch_no: string
  expiry: string
  units_per_pack: number
  packs: number
  free_packs: number
  pack_price: number
  pack_mrp: number
  unit_mrp: number
  discount_percent: number
  gst_percent: number
  units_received: number
  units_in_stock: number
  rack_no: string | null
  medicine?: { id: number; name: string; reorder_level?: number }
  invoice?: { id: number; invoice_no: string } | null
}

export type PurchaseInvoice = {
  id: number
  invoice_no: string
  invoice_date: string
  subtotal: number
  tax: number
  total: number
  remarks: string | null
  stockist: { id: number; name: string }
  batches_count?: number
  batches?: StockBatch[]
}

export type SellableMedicine = {
  id: number
  name: string
  type: string | null
  sellable_batches: Pick<StockBatch, 'id' | 'batch_no' | 'expiry' | 'unit_mrp' | 'gst_percent' | 'units_in_stock' | 'rack_no'>[]
}

export type PharmacySaleItem = {
  id: number
  medicine_name: string
  batch_no: string
  expiry: string
  quantity: number
  unit_price: number
  discount_percent: number
  gst_percent: number
  amount: number
}

export type PharmacySale = {
  id: number
  bill_no: string
  customer_name: string
  customer_phone: string | null
  referred_doctor: string | null
  sold_at: string
  subtotal: number
  discount: number
  tax: number
  round_off: number
  total: number
  paid: number
  ipd_transfer: number
  due: number
  payment_mode: string
  reference: string | null
  items_count?: number
  items?: PharmacySaleItem[]
  patient?: { id: number; uhid: string; name: string } | null
  seller?: { id: number; name: string } | null
}

export type Bed = {
  id: number
  ward: string
  bed_no: string
  daily_charge: number
  is_active: boolean
  current_admission?: { id: number; ipd_no: string; admitted_at: string; patient: { id: number; name: string; uhid: string } } | null
}

export type IpdService = { id: number; name: string; category: string | null; price: number; is_active: boolean }

export type AdmissionStatus = 'admitted' | 'discharged' | 'lama' | 'dama' | 'absconded' | 'died'

export type AdmissionCharge = {
  id: number
  description: string
  category: string | null
  charged_on: string
  quantity: number
  unit_price: number
  amount: number
}

export type Admission = {
  id: number
  ipd_no: string
  status: AdmissionStatus
  admitted_at: string
  discharged_at: string | null
  reason: string | null
  attendant_name: string | null
  attendant_phone: string | null
  discharge_summary: string | null
  total: number
  discount: number
  net: number
  paid: number
  due: number
  patient: Pick<Patient, 'id' | 'uhid' | 'name' | 'gender' | 'age' | 'mobile' | 'address' | 'city' | 'blood_group'>
  doctor: { id: number; name: string; department?: string | null; signature_text?: string | null }
  bed: { id: number; ward: string; bed_no: string; daily_charge?: number } | null
  charges?: AdmissionCharge[]
  payments?: (Omit<Payment, 'type'> & { type: 'advance' | 'payment' | 'refund' })[]
}

export type PrescriptionForPharmacy = {
  id: number
  completed_at: string
  doctor: string | null
  medicines: { medicine: string; directions: string; match: SellableMedicine | null }[]
}

export type OutstandingBill = { type: 'bill' | 'pharmacy'; id: number; label: string; category: string; date: string; due: number }
