const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../../assets/js/operations.js'), 'utf8');
function setup(rest, responses) {
  class Element {
    constructor() { this.children = []; this.listeners = {}; this.value = ''; this.disabled = false; this.style = {}; }
    append(...items) { this.children.push(...items); }
    replaceChildren() { this.children = []; }
    setAttribute() {} removeAttribute() {}
    addEventListener(type, fn) { this.listeners[type] = fn; }
  }
  const fields = {}; ['operations-status','scan','scan-submit','scan-result','stage','stage-submit','export-submit','export-result'].forEach(key => fields[key] = new Element());
  const root = new Element(); root.querySelector = selector => fields[selector.slice(11, -1)];
  const requests = [];
  const config = {rest,nonce:'nonce',working:'Working',failed:'Failed',complete:'Complete',order:'Order',item:'Item',sku:'SKU',quantity:'Quantity',weight:'kg',unknown:'Unknown'};
  vm.runInNewContext(source, {window:{wcipOperations:config,crypto:{randomUUID:()=> 'event-123'}}, document:{querySelector:()=>root,createElement:()=>new Element()}, Date, Uint8Array, Map, fetch:async(url,options)=> {requests.push({url,options}); const result = responses.shift(); return {ok:result.ok !== false,json:async()=>result.data};}});
  async function click(key) { fields[key].listeners.click(); await new Promise(resolve => setImmediate(resolve)); }
  return {fields,requests,click};
}
const scan = {reference:'W1-abcdefghijklmnop',fulfillment:{stage:'preparing',revision:1},data:{order_id:12,order_number:'12',items:[{name:'<script>bad</script>',sku:'',quantity:1.5,weight:{line_kg:null}}]}};
test('scanner sends nonce with plain and pretty REST URLs and renders text safely', async () => {
 for (const rest of ['https://example.test/?rest_route=/wc-invoice-printer/v1','https://example.test/wp-json/wc-invoice-printer/v1/']) {
  const env = setup(rest,[{data:scan}]); env.fields.scan.value='W1-abcdefghijklmnop'; await env.click('scan-submit');
  const url = new URL(env.requests[0].url);
  assert.equal(url.searchParams.get('rest_route') || url.pathname, rest.includes('?') ? '/wc-invoice-printer/v1/scan' : '/wp-json/wc-invoice-printer/v1/scan');
  assert.equal(env.requests[0].options.headers['X-WP-Nonce'],'nonce');
  assert.equal(env.fields['scan-result'].children[1].children[1].children[0].children[0].textContent,'<script>bad</script>');
  assert.equal(env.fields['operations-status'].textContent,'Complete');
 }
});
test('failed scan disables previous order actions and announces the error', async () => {
 const env = setup('https://example.test/wp-json/wc-invoice-printer/v1',[{data:scan},{ok:false,data:{message:'Unavailable'}}]);
 env.fields.scan.value='W1-abcdefghijklmnop'; await env.click('scan-submit'); assert.equal(env.fields['stage-submit'].disabled,false);
 env.fields.scan.value='W1-differentcode123'; await env.click('scan-submit');
 assert.equal(env.fields['stage-submit'].disabled,true); assert.equal(env.fields['export-submit'].disabled,true); assert.equal(env.fields['scan-result'].children.length,0); assert.equal(env.fields['operations-status'].textContent,'Unavailable');
});
test('duplicate HID scan is debounced and repeated export preserves event identity', async () => {
 const env = setup('https://example.test/wp-json/wc-invoice-printer/v1',[{data:scan},{data:{id:1,status:'queued'}},{data:{id:1,status:'queued'}}]); env.fields.scan.value='W1-abcdefghijklmnop'; await env.click('scan-submit'); await env.click('scan-submit'); assert.equal(env.requests.length,1);
 await env.click('export-submit'); await env.click('export-submit'); assert.equal(env.requests.length,3); assert.equal(env.requests[1].options.body,env.requests[2].options.body);
});
