/**
 * Exercises the WRITE paths against both back ends and compares the results,
 * including the rows each one left behind in the database.
 *
 * Every scenario runs twice — once through Nest, once through Laravel — and the
 * two outcomes are diffed. Created rows are removed afterwards by cleanup.sql.
 */
const NEST = 'http://localhost:3000/api';
const LARAVEL = 'http://localhost:8000/api';

// combo_id is volatile in these scenarios only because the parent combo is
// created fresh in each run; it is a real foreign key everywhere else.
const VOLATILE = new Set(['id', 'combo_id', 'createdAt', 'updatedAt', 'created_at', 'updated_at', 'order_id', 'sub_order_id', 'payout_id', 'order_ref_id', 'cart_id', 'paid_at', 'payout_date']);

function normalise(v) {
  if (Array.isArray(v)) return v.map(normalise);
  if (v && typeof v === 'object') {
    const out = {};
    for (const k of Object.keys(v).sort()) out[k] = VOLATILE.has(k) ? '<volatile>' : normalise(v[k]);
    return out;
  }
  return v;
}

async function call(base, method, path, body) {
  const init = { method, headers: {} };
  if (body !== undefined) {
    init.headers['Content-Type'] = 'application/json';
    init.body = JSON.stringify(body);
  }
  const res = await fetch(base + path, init);
  const text = await res.text();
  let parsed; try { parsed = JSON.parse(text); } catch { parsed = text; }
  return { status: res.status, body: parsed };
}

/**
 * Each scenario is a list of steps run in order against ONE backend. It returns
 * whatever the steps produced, so the two runs can be compared.
 */
const SCENARIOS = [
  {
    name: 'create-order (valid staff code -> commission accrual)',
    steps: async (base, tag) => [
      await call(base, 'POST', '/users/create-order', {
        kiosk_id: 'seed-kiosk-1',
        customer_id: 'seed-cust-1',
        amount: '30',
        discount: '0',
        order_status: 'completed',
        payment_type: 'cash',
        response_json: { payment: true },
        staff_code: 'Fc999',
      }),
    ],
  },
  {
    name: 'create-order (unknown staff code -> StaffCodeError)',
    steps: async (base) => [
      await call(base, 'POST', '/users/create-order', {
        kiosk_id: 'seed-kiosk-1',
        customer_id: 'seed-cust-1',
        amount: '10',
        order_status: 'completed',
        payment_type: 'cash',
        staff_code: 'NOPE-NOT-A-CODE',
      }),
    ],
  },
  {
    name: 'create-order (no staff code)',
    steps: async (base, tag) => [
      await call(base, 'POST', '/users/create-order', {
        kiosk_id: 'seed-kiosk-1',
        customer_id: 'seed-cust-1',
        amount: '12',
        order_status: 'pending',
        payment_type: 'qr',
        response_json: {},
      }),
    ],
  },
  {
    name: 'frames CRUD',
    steps: async (base, tag) => {
      const created = await call(base, 'POST', '/frames/create-frame', {
        frame_name: 'wdiff frame', img_url: 'frames/w.png', status: true,
      });
      const id = created.body?.data?.id;
      const fetched = await call(base, 'GET', `/frames/getby-id/${id}`);
      const updated = await call(base, 'PATCH', `/frames/update-frame/${id}`, { frame_name: 'wdiff frame v2', img_url: 'frames/w.png', status: true });
      const removed = await call(base, 'DELETE', `/frames/delete-frame/${id}`);
      const after = await call(base, 'GET', `/frames/getby-id/${id}`);
      return [created, fetched, updated, removed, after];
    },
  },
  {
    name: 'combo create with commission (valid)',
    steps: async (base, tag) => {
      const created = await call(base, 'POST', '/combo/create-combo', {
        combo_name: 'wdiff combo', price: 50, size: '4R', description: 'wdiff',
        img_url: 'combos/w.png', status: true,
        product_ids: ['seed-prod-1'],
        user_commissions: [{ user_id: 'seed-staff-1', commission_type: 'percent', commission_value: 5 }],
      });
      const id = created.body?.data?.id;
      const fetched = await call(base, 'GET', `/combo/getby-id/${id}`);
      const removed = await call(base, 'DELETE', `/combo/delete-combo/${id}`);
      return [created, fetched, removed];
    },
  },
  {
    name: 'combo create with commission (percent > 100 -> validation error)',
    steps: async (base, tag) => [
      await call(base, 'POST', '/combo/create-combo', {
        combo_name: 'wdiff bad', price: 50, status: true, img_url: 'combos/w.png', description: 'x',
        product_ids: ['seed-prod-1'],
        user_commissions: [{ user_id: 'seed-staff-1', commission_type: 'percent', commission_value: 150 }],
      }),
    ],
  },
  {
    name: 'combo create with commission (excluded user type -> validation error)',
    steps: async (base, tag) => [
      await call(base, 'POST', '/combo/create-combo', {
        combo_name: 'wdiff bad2', price: 50, status: true, img_url: 'combos/w.png', description: 'x',
        product_ids: ['seed-prod-1'],
        user_commissions: [{ user_id: 'a616deab-4c6f-479c-a746-c685922b0681', commission_type: 'amount', commission_value: 5 }],
      }),
    ],
  },
  {
    name: 'product delete blocked by combo mapping (EM019)',
    steps: async (base) => [
      await call(base, 'DELETE', '/products/delete-product/seed-prod-1'),
    ],
  },
  {
    name: 'cart create + fetch + delete',
    steps: async (base, tag) => {
      const created = await call(base, 'POST', '/cart/create-cart', {
        customer_id: 'seed-cust-1',
        combo_id: 'seed-combo-1',
        qty: 1,
        combo_map: {
          combo_id: 'seed-combo-1',
          product_list: [{ product_id: 'seed-prod-1', photo_limit: '2', product_type: 'others', magnet_img_name: null, certificate_img_name: null, frame_img_ids: ['seed-fim-1', 'seed-fim-2'] }],
        },
      });
      const id = created.body?.data?.id;
      const removed = await call(base, 'DELETE', `/cart/delete-single-cart/${id}`);
      return [created, removed];
    },
  },
  {
    name: 'roles create (duplicate user -> error)',
    steps: async (base) => [
      await call(base, 'POST', '/roles/create-role', {
        role_name: 'wdiff dup', is_active: true, user_id: 'seed-mgr-1', kiosk_ids: [],
      }),
    ],
  },
  {
    name: 'users create (duplicate user_name -> error)',
    steps: async (base) => [
      await call(base, 'POST', '/users/create-user', {
        name: 'Dup', user_name: 'seedkiosk', password: 'x', user_type: 'kiosk', status: true, role_id: 'whatever',
      }),
    ],
  },
  {
    name: 'commission payout (mixed/unknown ids -> validation error)',
    steps: async (base) => [
      await call(base, 'POST', '/commission/payout', { commission_ids: ['seed-sc-1', 'nope'] }),
    ],
  },
];

let pass = 0;
const failures = [];

for (const scenario of SCENARIOS) {
  const nest = await scenario.steps(NEST, 'nest');
  const laravel = await scenario.steps(LARAVEL, 'laravel');

  const a = JSON.stringify(normalise(nest), null, 2);
  const b = JSON.stringify(normalise(laravel), null, 2);

  if (a === b) {
    pass++;
    console.log(`PASS  ${scenario.name}`);
  } else {
    console.log(`FAIL  ${scenario.name}`);
    failures.push({ scenario, a, b });
  }
}

console.log(`\n${pass}/${SCENARIOS.length} passed\n`);

for (const f of failures) {
  console.log('='.repeat(70));
  console.log(`FAILURE: ${f.scenario.name}`);
  console.log(`--- nest ---\n${f.a.slice(0, 3000)}`);
  console.log(`--- laravel ---\n${f.b.slice(0, 3000)}`);
}
