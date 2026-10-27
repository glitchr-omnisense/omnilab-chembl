# omnilab/chembl

ChEMBL (EMBL-EBI), through its data web services (`https://www.ebi.ac.uk/chembl/api/data/`) and
UniChem, for [omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): bioactive molecules
by ChEMBL id, InChIKey or name - development phase, computed properties, mechanisms of action and
their targets - with their ChEBI, PubChem, UNII and DSSTox ids from UniChem as cross-references, as
entities; a search. No key.

## Installation

```sh
composer require omnilab/chembl
```

```php
use Omnilab\Chembl\ChemblSourceFactory;

$chembl = (new ChemblSourceFactory($httpClient))->create([
    'mailto' => 'support@glitchr.io',  // optional
    'synonyms' => 20,                  // optional
    'unichem' => true,                 // optional: the cross-references from UniChem
    'mechanisms' => true,              // optional: the mechanisms of action and their targets
]);
```

With the Symfony bundle, the factory is registered as `chembl` when the bundle knows it; otherwise
register `ChemblSourceFactory` as a service (it is autoconfigured as a `SourceFactoryInterface`).

## Credentials

None: ChEMBL's data web services and UniChem take no key. Give `mailto`, the address the EBI writes
to before it blocks a caller.

## Options

The factory's options, with the defaults it applies (`ChemblSourceFactory`):

| Option | Type | Default | Required | Effect |
|---|---|---|---|---|
| `synonyms` | `int` | `20` | no | how many synonyms to keep; 0: none |
| `unichem` | `bool` | `true` | no | `entity()` asks UniChem for the molecule's ChEBI, PubChem, UNII and DSSTox ids (one POST) |
| `mechanisms` | `bool` | `true` | no | `entity()` asks the mechanisms of action and their targets (one or two calls) |
| `unichem_uri` | `string` | `https://www.ebi.ac.uk/unichem/api/v1/compounds` | no | UniChem's compounds API, for the cross-references |
| `mailto` | `?string` | null | no | a contact address, in the User-Agent: whom the EBI writes to before it blocks a caller |
| `user_agent` | `?string` | null | no | the whole User-Agent, in place of the one made from `mailto` |
| `throttle` | `float` | `0` | no | seconds left between two calls of the data web services (UniChem's POST is not spaced): the EBI publishes no figure |
| `base_uri` | `string` | `https://www.ebi.ac.uk/chembl/api/data/` | no | ChEMBL's data web services |

## Capabilities

`capabilities()` answers `entity`, `search`, `structure`, `cross_references`; an operation it does not answer throws `NotSupportedException` before any call.

| Capability | Supported | |
|---|---|---|
| `entity` | yes | a ChEMBL id (`CHEMBL113`, `chembl:`, its page), a standard InChIKey, or a name (preferred, then synonym); another scheme refused before any call; up to four calls |
| `search` | yes | a full InChIKey or its first block (matched on the structures), else ChEMBL's full-text search, the best first, 100 a page at most, paged by offset, with a `total`; a domain other than chemistry answers an empty page |
| `series` | no |  |
| `products` | no |  |
| `occurrences` | no |  |
| `datasets` | no |  |
| `jobs` | no |  |
| `open` | no |  |
| `structure` | yes | the image is ChEMBL's SVG of the structure (none for an antibody) |
| `cross_references` | yes | the InChIKey and InChI, and from UniChem the ChEBI, PubChem, UNII and DSSTox ids; the mechanisms' targets are `related` |
| `forecast` | no |  |
| `historical` | no |  |
| `area` | no |  |
| `station` | no |  |
| `cloud_cover` | no |  |

## Which API

The ChEMBL data web services, `https://www.ebi.ac.uk/chembl/api/data/` (ChEMBL 37, released
2026-05-01, as `status.json` says), in JSON. A molecule's record holds its names, type, phase,
computed properties and structures, but no identifier of another base beyond the InChI and its
key; those ChEMBL keeps in UniChem, its sister service at the EBI
(`https://www.ebi.ac.uk/unichem/api/v1/compounds`), which answers only POST: the source posts the
ChEMBL id there. UniChem's legacy GET interface (`/unichem/rest/`) answers 404.

## Calls

| Method | Reads | ChEMBL |
|---|---|---|
| `entity()` | a ChEMBL id (`CHEMBL113`, `chembl:CHEMBL113`, `chembl.compound:CHEMBL113`, its page) | `molecule/{id}.json` |
| | a standard InChIKey | `molecule/{inchikey}.json`: ChEMBL reads a key where it reads an id |
| | a name | `molecule.json?pref_name__iexact=`; when none, `molecule.json?molecule_synonyms__molecule_synonym__iexact=` |
| | then, for each | UniChem `POST compounds {"type": "sourceID", "sourceID": 1, "compound": id}`; `mechanism.json?molecule_chembl_id=`; `target/set/{ids}.json` with their components |
| `search()` | a full InChIKey | `molecule.json?molecule_structures__standard_inchi_key=` |
| | an InChIKey's first block (14 letters: the skeleton) | `molecule.json?molecule_structures__standard_inchi_key__startswith=` |
| | any other text; a domain other than chemistry answers nothing | `molecule/search.json?q=` (names, synonyms, ids; the best first) |

A molecule costs one call, two by a synonym, then one to UniChem and one or two for the
mechanisms (the targets' names in one call for all) - four for caffeine; `unichem: false` and
`mechanisms: false` leave those out. A search pages by offset (`Page::$next` is the next offset)
and reads the molecules from its records: no UniChem nor mechanisms there, `entity()` adds them.

An identifier of a scheme ChEMBL does not read (a PubChem CID, ChEBI, CAS, Wikidata, UniProt...)
is refused before any call; unknown is `null` (ChEMBL answers 404 with an empty body). A name
that several molecules have as a synonym, none as its preferred name, is `null` rather than a
guess.

## What an entity holds

`id` (its ChEMBL id), `label` (ChEMBL's preferred name in the case of the synonym that spells it -
`Caffeine`, not ChEMBL's `CAFFEINE`; its id when it has no name), `domain` chemistry, `kind`
(ChEMBL's molecule type, lower-cased: `small molecule`, `antibody`, `protein`,
`oligonucleotide`...), `url` (`https://www.ebi.ac.uk/chembl/explore/compound/CHEMBL113`), `image`
(the SVG of its structure, `image/{id}.svg`: `Capability::STRUCTURE`; null without a structure, as
for an antibody), `synonyms` (ChEMBL's synonyms but its trade names, research codes and registry
numbers, 20 by default), no description (ChEMBL writes none for a small molecule), and these
properties:

| Key | ChEMBL | Value |
|---|---|---|
| `molecular_formula` | full_molformula | text |
| `molecular_weight` | full_mwt (the salt's, for a salt) | Quantity, g/mol |
| `molecular_weight_parent` | mw_freebase | Quantity, g/mol |
| `alogp` | alogp (Crippen) | number |
| `tpsa` | psa | Quantity, Å² |
| `h_bond_donors`, `h_bond_acceptors` | hbd, hba | number |
| `rotatable_bonds`, `heavy_atoms`, `aromatic_rings` | rtb, heavy_atoms, aromatic_rings | number |
| `ro5_violations` | num_ro5_violations | number |
| `rule_of_five` | at most one violation of Lipinski's rule of five | boolean |
| `rule_of_three` | ro3_pass (fragment-like) | boolean |
| `qed`, `np_likeness` | qed_weighted, np_likeness_score | number |
| `smiles`, `inchi`, `inchikey` | the molecule's structures (canonical SMILES, standard InChI and key) | text |
| `max_phase` | max_phase: 4 approved, 3, 2, 1, 0.5 early phase 1, -1 unknown; absent before the clinic | number |
| `first_approval` | first_approval | year |
| `atc_code` | atc_classifications, one property each | text |
| `natural_product`, `withdrawn` | natural_product, withdrawn_flag | boolean |
| `parent_chembl_id` | the parent of a salt (caffeine citrate's is CHEMBL113) | text |
| `mechanism_of_action`, `action_type`, `target`, `target_chembl_id` | each curated mechanism: what it does, the action (lower-cased), the target's name with its type and organism, its id | text |

Keys shared with PubChem (`molecular_weight`, `tpsa`, `h_bond_donors`, `h_bond_acceptors`,
`rotatable_bonds`, `heavy_atoms`, `smiles`, `inchi`, `inchikey`) name the same quantity, computed
by each base in its own way: ChEMBL's polar surface for caffeine is 61.82 Å², PubChem's 58.4;
ChEMBL counts 6 acceptors, PubChem 3. After a merge both are kept, each with its source. ChEMBL's
`alogp` is not PubChem's `xlogp`: two methods, two keys.

`identifiers`: the InChIKey and the InChI, then UniChem's ChEBI id, PubChem CID, UNII and
DSSTox id when it has them (caffeine: CHEBI:27732, CID 2519, 3G6A5W338E, DTXSID0020232). UniChem
lists more bases (DrugBank, HMDB, the PDB ligand, Guide to Pharmacology, clinical trials...):
the core has no scheme for them.

`related`: what the molecule acts on, not what it is - `target`, the ChEMBL ids of its
mechanisms' targets (caffeine: CHEMBL2111329, the adenosine receptor family), and
`target_protein`, the UniProt accessions of their proteins (P30542, P29275, P0DMS8, P29274: the
receptors A1, A2b, A3 and A2a). The Collector does not follow them; an application can ask a
UniProt source for them.

## The Collector: caffeine from PubChem

PubChem gives caffeine's InChIKey before its ChEMBL id; ChEMBL reads both, so the `Collector`
asks it by the key. Against the real services on 2026-10-15:

```php
$collector->collect('pubchem', 'caffeine', 1, ['chembl']);
```

8 calls in 3.4 s - PubChem's four, then `molecule/RYYVLZVUVIJVGH-UHFFFAOYSA-N.json`, UniChem,
`mechanism.json`, `target/set/CHEMBL2111329.json` - and `sources` = pubchem, chembl:

```
molecular_weight      194.19 g/mol [pubchem]  194.19 g/mol [chembl]
tpsa                  58.4 Å² [pubchem]  61.82 Å² [chembl]
h_bond_acceptors      3 [pubchem]  6 [chembl]
xlogp                 -0.1 [pubchem]
alogp                 -1.03 [chembl]
max_phase             4 [chembl]
rule_of_five          yes [chembl]
mechanism_of_action   Adenosine receptor antagonist [chembl]
target                Adenosine receptor (protein family, Homo sapiens) [chembl]
```

From ChEMBL the other way, UniChem's CID 2519 and ChEBI 27732 lead to PubChem and ChEBI.

## What ChEMBL does not give here

The bioactivities (`activity`: 24.5 million measurements), the assays, the documents, the drug
indications, the warnings beyond the withdrawn flag, the routes (oral, parenteral, topical), the
biotherapeutic's sequence and description, the molfile, the similarity and substructure
searches and the targets' own records are not read. Nor are the cross-references UniChem gives
in bases the core has no scheme for.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\Chembl\ChemblSourceFactory;
use Omnilab\Model\Scheme;
use Omnilab\Source\Query;
use Symfony\Component\HttpClient\HttpClient;

$chembl = (new ChemblSourceFactory(HttpClient::create()))->create(['mailto' => 'you@example.org', 'synonyms' => 3]);

$aspirin = $chembl->entity('aspirin');
echo $aspirin->label, ' (', $aspirin->id, '): phase ', $aspirin->value('max_phase'), ', ', $aspirin->property('molecular_weight'), ', mechanism: ', $aspirin->value('mechanism_of_action'), "\n";
echo '  ChEBI ', $aspirin->identifier(Scheme::CHEBI), ', PubChem ', $aspirin->identifier(Scheme::PUBCHEM_CID), ', UNII ', $aspirin->identifier(Scheme::UNII), '; targets ', implode(', ', $aspirin->related('target')->values(Scheme::CHEMBL)), "\n";

$page = $chembl->search(new Query('caffeine', limit: 3));
printf("%d molecules for \"caffeine\"; the first %d: %s\n", $page->total, count($page), implode(', ', array_map(static fn ($m) => $m->id->value.' '.$m->label, $page->items)));
```

```
Aspirin (chembl:CHEMBL25): phase 4, 180.16 g/mol, mechanism: Cyclooxygenase inhibitor
  ChEBI 15365, PubChem 2244, UNII R16CO5Y76E; targets CHEMBL2094253
110 molecules for "caffeine"; the first 3: CHEMBL113 Caffeine, CHEMBL1200569 Caffeine citrate, CHEMBL1927665 CHEMBL1927665
```

(as answered on 2026-10-27: five calls - the name, UniChem, the mechanisms and their target, the search)

```yaml
# config/packages/omnilab.yaml
omnilab:
    sources:
        chembl: { factory: chembl, options: { mailto: '%env(OMNILAB_MAILTO)%', synonyms: 20, unichem: true, mechanisms: true } }
```

## Errors

Every call goes through `HttpSource`: a 404 or a 410 (and a 204) is null - an empty page for a list -; a 429 is a `RateLimitedException` (`retryAfter` from `Retry-After`); a 5xx, a time-out or a host that does not answer an `UnavailableException`; any other 4xx a `ProviderException` with its `status` and what the service said; an answer that is not JSON a `ProviderException`, a web page answered for JSON an `UnavailableException`.

ChEMBL's own: an unknown id is its 404 with an empty body, null; a name several molecules have as
a synonym, none as its preferred name, is null. UniChem's POST is made by the source and mapped
alike (a 429 a `RateLimitedException`, a 5xx an `UnavailableException`, another 4xx a
`ProviderException`, "UniChem answered ..."); UniChem's "Not found" (an antibody) leaves the
cross-references out. Before any call: an identifier of a scheme ChEMBL does not read (a PubChem
CID, ChEBI, CAS, Wikidata, UniProt...) (`NotSupportedException`).

## Verified, and not

Against the real services on 2026-10-15, without a key (ChEMBL 37): caffeine by its ChEMBL id,
its InChIKey, its name and its synonym "guaranine"; its properties, phase, ATC codes, mechanism
and target; its ChEBI, PubChem, UNII and DSSTox ids from UniChem; aspirin by name (CHEMBL25,
cyclooxygenase); trastuzumab (CHEMBL1201585, an antibody: no structure, no image, UniChem "Not
found", HER2 as target); an unknown id (404, empty body) and an unknown name; a search for
"caffeine" (110 molecules, offsets 0 and 3), by caffeine's InChIKey and by its first block (and
aspirin's); the SVG of the structure (200, `image/svg+xml`; 404 for the antibody); the
Collector's walk above. The fixtures in `Tests/Fixtures/` are its answers, UniChem's 177 clinical
trials for caffeine cut to 2.

Again on 2026-10-27: aspirin by its name (phase 4, its UniChem ids, its target), the search for
"caffeine" (110 molecules; the example above).

Not verified: ChEMBL's or UniChem's answer to a client that calls too fast (no 429 met, no rate
published - the handling is the core's); a full-text search of an InChIKey, which ChEMBL answers
with 10,000 unrelated molecules, which is why a key is matched on the structures instead. The
UniChem call is a POST the core's `HttpSource` does not make: the source maps its errors the
same way, but the `throttle` option does not space it.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by ChEMBL or the EMBL-EBI. ChEMBL's data are under
[CC BY-SA 3.0](https://creativecommons.org/licenses/by-sa/3.0/) - cite ChEMBL, share what is derived
from it alike - and the [EMBL-EBI's terms of use](https://www.ebi.ac.uk/about/terms-of-use) reserve
the right to block a client that loads the services unreasonably, as the README's
[Terms of use](https://github.com/glitchr-omnisense/omnilab-chembl#terms-of-use) says. Whoever runs
the package is responsible for complying with them and for any use made of what it returns: see
the README's [Responsibility](https://github.com/glitchr-omnisense/omnilab-chembl#responsibility)
section.
