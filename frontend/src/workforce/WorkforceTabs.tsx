import { GroupedTabs, type TabGroup } from '../components/GroupedTabs'
import { useWorkforce } from './shared'

/** Every workforce page: the entry it sits under, the permission that opens it, its address and its name. */
const PAGES: [string, string, string, string][] = [
  ['Today', 'wf:reports:view', '/workforce', 'Today'],
  ['Staff', 'wf:staff:view', '/workforce/staff', 'Staff'],
  ['Attendance', 'wf:attendance:view', '/workforce/attendance', 'Attendance'],
  ['Leave', 'wf:leave:view', '/workforce/leave', 'Leave'],
  // Everything about money paid to staff sits under one entry.
  ['Pay', 'wf:salary:view', '/workforce/pay', 'Salaries'],
  ['Pay', 'wf:payroll:view', '/workforce/payroll', 'Payroll'],
  ['Pay', 'wf:advances:view', '/workforce/advances', 'Advances'],
  // Doctors and consultants are not in the payroll: what they earn and are paid is kept here.
  ['Pay', 'canPayConsultants', '/workforce/consultants', 'Consultants'],
  ['Setup', 'wf:settings:admin', '/workforce/setup', 'Setup'],
  ['My record', 'wf:self:view', '/workforce/me', 'My record'],
]

/** The workforce pages a person may open, on one line under the top bar. */
export function WorkforceTabs() {
  const { can } = useWorkforce()
  const groups: TabGroup[] = []
  for (const [group, permission, to, name] of PAGES) {
    if (!can(permission)) continue
    const last = groups[groups.length - 1]
    if (last?.[0] === group) last[1].push([to, name])
    else groups.push([group, [[to, name]]])
  }
  return <GroupedTabs label="Workforce pages" groups={groups} exact={['/workforce']} />
}
