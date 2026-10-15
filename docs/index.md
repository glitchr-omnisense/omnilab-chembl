# omnilab/chembl

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

Not verified: ChEMBL's or UniChem's answer to a client that calls too fast (no 429 met, no rate
published - the handling is the core's); a full-text search of an InChIKey, which ChEMBL answers
with 10,000 unrelated molecules, which is why a key is matched on the structures instead. The
UniChem call is a POST the core's `HttpSource` does not make: the source maps its errors the
same way, but the `throttle` option does not space it.
