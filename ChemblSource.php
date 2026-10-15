<?php

namespace Omnilab\Chembl;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Exception\RateLimitedException;
use Omnilab\Exception\UnavailableException;
use Omnilab\Model\Domain;
use Omnilab\Model\Entity;
use Omnilab\Model\Identifier;
use Omnilab\Model\Identifiers;
use Omnilab\Model\Property;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\Page;
use Omnilab\Source\Query;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * ChEMBL, the EMBL-EBI's database of bioactive molecules with drug-like
 * properties, through its data web services (www.ebi.ac.uk/chembl/api/data):
 * a molecule by its ChEMBL id, its InChIKey or its name; its type, its
 * maximum development phase, its computed properties (weight, ALogP, polar
 * surface, hydrogen bond donors and acceptors, Lipinski's rule of five),
 * its structure, its mechanisms of action and their targets, and its
 * identifiers in ChEBI, PubChem and the other bases, which ChEMBL keeps in
 * UniChem; a search by text or InChIKey.
 *
 * No key, no account. A molecule costs one call, then one to UniChem for its
 * cross-references and one or two for its mechanisms and their targets
 * (each can be turned off).
 */
final class ChemblSource extends HttpSource
{
    public const BASE_URI = 'https://www.ebi.ac.uk/chembl/api/data/';

    public const UNICHEM_URI = 'https://www.ebi.ac.uk/unichem/api/v1/compounds';

    /** The most molecules one search page is asked for (ChEMBL's own cap is 1000). */
    public const MAX_PER_PAGE = 100;

    /** UniChem's source ids => the scheme of their compound ids. */
    private const UNICHEM = [7 => Scheme::CHEBI, 22 => Scheme::PUBCHEM_CID, 14 => Scheme::UNII, 32 => Scheme::DSSTOX];

    /** The kinds of synonyms not kept: brand names and research codes. */
    private const SKIPPED_SYNONYMS = ['TRADE_NAME', 'RESEARCH_CODE'];

    /**
     * @param int  $synonyms   how many synonyms to keep (0: none)
     * @param bool $unichem    ask UniChem for the identifiers of other bases (one call)
     * @param bool $mechanisms ask the mechanisms of action and their targets (one or two calls)
     */
    public function __construct(
        HttpClientInterface $http,
        private readonly int $synonyms = 20,
        private readonly bool $unichem = true,
        private readonly bool $mechanisms = true,
        string $baseUri = self::BASE_URI,
        private readonly string $unichemUri = self::UNICHEM_URI,
        array $headers = [],
        float $throttle = 0.0,
    ) {
        parent::__construct($http, $baseUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return 'chembl';
    }

    public function capabilities(): array
    {
        return [Capability::ENTITY, Capability::SEARCH, Capability::STRUCTURE, Capability::CROSS_REFERENCES];
    }

    public function entity(Identifier|string $id): ?Entity
    {
        if ($id instanceof Identifier) {
            $identifier = \in_array($id->scheme, [Scheme::CHEMBL, Scheme::INCHIKEY], true) ? $id : throw NotSupportedException::identifier('chembl', (string) $id);
        } else {
            $text = trim($id);
            $identifier = Identifier::parse($text);
            if (null !== $identifier && !\in_array($identifier->scheme, [Scheme::CHEMBL, Scheme::INCHIKEY], true)) {
                throw NotSupportedException::identifier('chembl', $id);
            }
            if (null === $identifier) {
                $molecule = '' === $text ? null : $this->byName($text);

                return null === $molecule ? null : $this->complete($molecule);
            }
        }
        // ChEMBL reads a standard InChIKey where it reads an id.
        $molecule = $this->getJson('molecule/'.rawurlencode($identifier->value).'.json');

        return null === $molecule || !isset($molecule['molecule_chembl_id']) ? null : $this->complete($molecule);
    }

    /**
     * ChEMBL's search: an InChIKey (or its first block, the skeleton) is
     * matched on the standard InChIKeys, any other text by the full-text
     * search over names, synonyms and ids, the best first. Each molecule is
     * read from the search's records: entity() adds the cross-references
     * and the mechanisms.
     */
    public function search(Query $query): Page
    {
        if (null !== $query->domain && Domain::CHEMISTRY !== $query->domain) {
            return new Page();
        }
        $text = trim($query->text);
        if ('' === $text) {
            return new Page();
        }
        $offset = max(0, (int) $query->cursor);
        $limit = max(1, min(self::MAX_PER_PAGE, $query->limit));
        $paging = ['limit' => $limit, 'offset' => $offset > 0 ? $offset : null];
        $upper = strtoupper($text);
        $data = match (true) {
            null !== Identifier::tryOf(Scheme::INCHIKEY, $upper) => $this->getJson('molecule.json', ['molecule_structures__standard_inchi_key' => $upper] + $paging),
            (bool) preg_match('~^[A-Z]{14}$~', $upper) => $this->getJson('molecule.json', ['molecule_structures__standard_inchi_key__startswith' => $upper.'-'] + $paging),
            default => $this->getJson('molecule/search.json', ['q' => $text] + $paging),
        };
        $entities = [];
        foreach ($data['molecules'] ?? [] as $molecule) {
            if (isset($molecule['molecule_chembl_id'])) {
                $entities[] = $this->fromMolecule($molecule);
            }
        }
        $total = isset($data['page_meta']['total_count']) ? (int) $data['page_meta']['total_count'] : null;
        $next = null !== ($data['page_meta']['next'] ?? null) ? (string) ($offset + $limit) : null;

        return new Page($entities, $next, $total);
    }

    /**
     * The molecule of that name: the one whose preferred name it is (whatever
     * its case), else the only one that has it as a synonym; null when
     * several have it and none prefers it.
     *
     * @return array<string, mixed>|null
     */
    private function byName(string $name): ?array
    {
        foreach (['pref_name__iexact', 'molecule_synonyms__molecule_synonym__iexact'] as $filter) {
            $found = [];
            foreach ($this->getJson('molecule.json', [$filter => $name, 'limit' => 5])['molecules'] ?? [] as $molecule) {
                $found[$molecule['molecule_chembl_id'] ?? ''] ??= $molecule;
            }
            unset($found['']);
            if (1 === \count($found)) {
                return reset($found);
            }
            if ($found) {
                return null;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $molecule */
    private function complete(array $molecule): Entity
    {
        $entity = $this->fromMolecule($molecule);
        $id = $entity->id->value;
        if ($this->unichem) {
            $entity = $entity->with(['identifiers' => $entity->identifiers->merge(new Identifiers($this->crossReferences($id)))]);
        }
        if ($this->mechanisms) {
            $entity = $entity->with(['properties' => [...$entity->properties, ...$this->mechanismsOf($id)]]);
        }

        return $entity;
    }

    /**
     * The identifiers UniChem knows the molecule under, in the schemes of the
     * core: ChEBI, PubChem, UNII, DSSTox.
     *
     * @return list<Identifier|null>
     */
    private function crossReferences(string $chemblId): array
    {
        $data = $this->postJson($this->unichemUri, ['type' => 'sourceID', 'compound' => $chemblId, 'sourceID' => 1]);
        $identifiers = [];
        foreach (self::UNICHEM as $source => $scheme) {
            foreach ($data['compounds'][0]['sources'] ?? [] as $entry) {
                if ($source === (int) ($entry['id'] ?? 0)) {
                    $identifiers[] = Identifier::tryOf($scheme, (string) ($entry['compoundId'] ?? ''));
                }
            }
        }

        return $identifiers;
    }

    /**
     * The mechanisms of action ChEMBL curated for the molecule: what it does
     * ("Adenosine receptor antagonist"), the action, the target by name and
     * by ChEMBL id; the targets' names in one more call.
     *
     * @return list<Property>
     */
    private function mechanismsOf(string $chemblId): array
    {
        $mechanisms = $this->getJson('mechanism.json', ['molecule_chembl_id' => $chemblId, 'limit' => 50])['mechanisms'] ?? [];
        $ids = array_values(array_unique(array_filter(array_map(static fn (array $m) => $m['target_chembl_id'] ?? null, $mechanisms))));
        $targets = [];
        if ($ids) {
            foreach ($this->getJson('target/set/'.implode(';', $ids).'.json', ['only' => 'target_chembl_id,pref_name,target_type,organism'])['targets'] ?? [] as $target) {
                $targets[$target['target_chembl_id'] ?? ''] = $target;
            }
        }
        $properties = [];
        foreach ($mechanisms as $mechanism) {
            $add = static function (string $key, string $label, ?string $value) use (&$properties): void {
                if (null !== $value && '' !== $value) {
                    $properties[] = new Property($key, $label, $value, 'chembl');
                }
            };
            $target = $targets[$mechanism['target_chembl_id'] ?? ''] ?? null;
            $add('mechanism_of_action', 'Mechanism of action', self::text($mechanism['mechanism_of_action'] ?? null));
            $add('action_type', 'Action type', null === ($action = self::text($mechanism['action_type'] ?? null)) ? null : strtolower($action));
            $add('target', 'Target', null === $target ? null : self::text($target['pref_name'] ?? null).self::details(strtolower((string) ($target['target_type'] ?? '')), (string) ($target['organism'] ?? '')));
            $add('target_chembl_id', 'Target (ChEMBL id)', $mechanism['target_chembl_id'] ?? null);
        }

        return $properties;
    }

    /** @param array<string, mixed> $molecule a molecule as ChEMBL gives it */
    private function fromMolecule(array $molecule): Entity
    {
        $id = Identifier::of(Scheme::CHEMBL, (string) $molecule['molecule_chembl_id']);
        $props = $molecule['molecule_properties'] ?? [];
        $structure = $molecule['molecule_structures'] ?? null;
        $number = static fn (mixed $v): int|float|null => is_numeric($v) ? (str_contains((string) $v, '.') ? (float) $v : (int) $v) : null;

        $properties = [];
        $add = static function (string $key, string $label, mixed $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'chembl');
            }
        };
        $quantity = static fn (mixed $v, string $unit): ?Quantity => null === ($n = $number($v)) ? null : new Quantity($n, $unit);
        $add('molecular_formula', 'Molecular formula', $props['full_molformula'] ?? null);
        $add('molecular_weight', 'Molecular weight', $quantity($props['full_mwt'] ?? null, 'g/mol'));
        $add('molecular_weight_parent', 'Molecular weight of the parent (free base)', $quantity($props['mw_freebase'] ?? null, 'g/mol'));
        $add('alogp', 'ALogP (computed octanol/water partition coefficient, Crippen)', $number($props['alogp'] ?? null));
        $add('tpsa', 'Topological polar surface area', $quantity($props['psa'] ?? null, 'Å²'));
        $add('h_bond_donors', 'Hydrogen bond donors', $number($props['hbd'] ?? null));
        $add('h_bond_acceptors', 'Hydrogen bond acceptors', $number($props['hba'] ?? null));
        $add('rotatable_bonds', 'Rotatable bonds', $number($props['rtb'] ?? null));
        $add('heavy_atoms', 'Heavy atoms', $number($props['heavy_atoms'] ?? null));
        $add('aromatic_rings', 'Aromatic rings', $number($props['aromatic_rings'] ?? null));
        $violations = $number($props['num_ro5_violations'] ?? null);
        $add('ro5_violations', 'Violations of Lipinski\'s rule of five', $violations);
        $add('rule_of_five', 'Lipinski\'s rule of five (at most one violation)', null === $violations ? null : $violations <= 1);
        $add('rule_of_three', 'Rule of three (fragment-like)', match ($props['ro3_pass'] ?? null) { 'Y' => true, 'N' => false, default => null });
        $add('qed', 'QED (quantitative estimate of drug-likeness)', $number($props['qed_weighted'] ?? null));
        $add('np_likeness', 'Natural-product likeness score', $number($props['np_likeness_score'] ?? null));
        $add('smiles', 'SMILES', $structure['canonical_smiles'] ?? null);
        $add('inchi', 'InChI', $structure['standard_inchi'] ?? null);
        $add('inchikey', 'InChIKey', $structure['standard_inchi_key'] ?? null);
        $add('max_phase', 'Maximum development phase (4: approved; 0.5: early phase 1; -1: unknown)', $number($molecule['max_phase'] ?? null));
        $add('first_approval', 'First approval (year)', $number($molecule['first_approval'] ?? null));
        foreach ($molecule['atc_classifications'] ?? [] as $atc) {
            $add('atc_code', 'ATC code', \is_string($atc) ? $atc : null);
        }
        $add('natural_product', 'Natural product', isset($molecule['natural_product']) && is_numeric($molecule['natural_product']) && $molecule['natural_product'] >= 0 ? 1 === (int) $molecule['natural_product'] : null);
        $add('withdrawn', 'Withdrawn from the market', \is_bool($molecule['withdrawn_flag'] ?? null) ? $molecule['withdrawn_flag'] : null);
        $parent = $molecule['molecule_hierarchy']['parent_chembl_id'] ?? null;
        $add('parent_chembl_id', 'Parent molecule (ChEMBL id)', null !== $parent && $parent !== $id->value ? $parent : null);

        $prefName = self::text($molecule['pref_name'] ?? null);
        $label = $prefName ?? $id->value;
        $synonyms = [];
        foreach ($molecule['molecule_synonyms'] ?? [] as $synonym) {
            $name = self::text($synonym['molecule_synonym'] ?? null);
            if (null === $name) {
                continue;
            }
            // ChEMBL writes its preferred names in capitals; the synonym that spells it is the label.
            if (null !== $prefName && $label === $prefName && mb_strtoupper($name) === $prefName) {
                $label = $name;
            }
            // Brand names, research codes and registry numbers are not names: "NSC-5036", "FEMA NO. 2224".
            if (\in_array($synonym['syn_type'] ?? null, self::SKIPPED_SYNONYMS, true) || (preg_match('~^[A-Z0-9]+(?:[\s:_-]+[A-Z0-9.]+)*$~', $name) && preg_match('~\d~', $name))) {
                continue;
            }
            $synonyms[mb_strtolower($name)] ??= $name;
        }
        unset($synonyms[mb_strtolower($label)]);

        $type = self::text($molecule['molecule_type'] ?? null);

        return new Entity(
            id: $id,
            label: $label,
            domain: Domain::CHEMISTRY,
            kind: null === $type ? null : strtolower($type),
            synonyms: \array_slice(array_values($synonyms), 0, $this->synonyms),
            properties: $properties,
            identifiers: new Identifiers([Identifier::tryOf(Scheme::INCHIKEY, $structure['standard_inchi_key'] ?? null), Identifier::tryOf(Scheme::INCHI, $structure['standard_inchi'] ?? null)]),
            url: 'https://www.ebi.ac.uk/chembl/explore/compound/'.$id->value,
            image: null !== $structure ? rtrim($this->baseUri, '/').'/image/'.$id->value.'.svg' : null,
            source: 'chembl',
        );
    }

    /** " (single protein, Homo sapiens)": what a target is, when ChEMBL says. */
    private static function details(string ...$parts): string
    {
        $parts = array_filter($parts, static fn (string $p) => '' !== $p);

        return $parts ? ' ('.implode(', ', $parts).')' : '';
    }

    /**
     * POSTs JSON and reads JSON (UniChem answers only POST), the errors
     * mapped as the core maps a GET's; null when the service answers 404.
     *
     * @param array<string, mixed> $body
     */
    private function postJson(string $url, array $body): ?array
    {
        try {
            $response = $this->http->request('POST', $url, ['json' => $body, 'headers' => ['Accept' => 'application/json'] + $this->headers]);
            $status = $response->getStatusCode();
            if (404 === $status) {
                return null;
            }
            if (429 === $status) {
                $retry = $response->getHeaders(false)['retry-after'][0] ?? null;
                throw new RateLimitedException($this->getName(), null !== $retry && is_numeric($retry) ? (int) $retry : null);
            }
            if ($status >= 500) {
                throw new UnavailableException($this->getName(), \sprintf('UniChem answered %d', $status), $status);
            }
            if ($status >= 400) {
                throw new ProviderException($this->getName(), \sprintf('UniChem answered %d', $status), $status);
            }
            $data = json_decode($response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

            return \is_array($data) ? $data : throw new ProviderException($this->getName(), 'UniChem\'s answer is not a JSON object');
        } catch (TransportExceptionInterface $e) {
            throw new UnavailableException($this->getName(), 'UniChem unreachable: '.$e->getMessage(), null, $e);
        } catch (\JsonException $e) {
            throw new ProviderException($this->getName(), 'UniChem\'s answer is not JSON: '.$e->getMessage(), null, $e);
        } catch (ExceptionInterface $e) {
            throw new ProviderException($this->getName(), $e->getMessage(), null, $e);
        }
    }
}
