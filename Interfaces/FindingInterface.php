<?php
namespace axenox\PackageManager\Interfaces;

use axenox\PackageManager\DataTypes\AdvisoryTypeDataType;

/**
 * Defines an immutable advisory for one package.
 * 
 * Implementations expose normalized properties from either one scanner or a
 * consumer-selected group. Consumers choose grouping and output formatting;
 * merged findings decide how to aggregate the properties of their originals.
 */
interface FindingInterface
{
    const TYPE_VULNERABILITY = AdvisoryTypeDataType::VULNERABILITY;
    const TYPE_EOL = AdvisoryTypeDataType::EOL;

    /**
     * Identifies a package that could not be checked for vulnerabilities.
     */
    const TYPE_UNSCANNABLE = AdvisoryTypeDataType::UNSCANNABLE;

    /**
     * Returns scanner-native identifiers.
     * 
     * Identifiers remain independent of consumer grouping and output IDs.
     * Merged findings separate distinct identifiers with a semicolon and space.
     * 
     * @return string
     */
    public function getSourceId() : string;

    /**
     * Returns the normalized severity.
     * 
    * Supported levels are 100 (low), 200 (medium), 300 (high) and 400 (critical).
     * 
    * @return int
     */
      public function getLevel() : int;

    /**
     * Returns the advisory title.
     * 
     * The title provides a readable label for reports and DataSheet results.
     * 
     * @return string
     */
    public function getName() : string;

    /**
     * Returns the finding type.
     * 
     * Vulnerability, end-of-life and unscannable findings use separate identities.
     * 
    * @return string One of the AdvisoryTypeDataType values.
     */
    public function getType() : string;

    /**
     * Returns the affected package name.
     * 
     * Each finding belongs to exactly one package, never a combined package list.
     * 
     * @return string
     */
    public function getPackage() : string;

    /**
     * Returns advisory URLs.
     * 
     * Multiple URLs are separated by a semicolon and space; unavailable URLs
     * are represented by an empty string.
     * 
     * @return string
     */
    public function getDetailsUrl() : string;

    /**
     * Returns scanner names.
     * 
     * Raw findings have one scanner; merged findings separate distinct scanner
     * names with a semicolon and space.
     * 
     * @return string
     */
    public function getSource() : string;

    /**
     * Returns source-native severity labels.
     * 
     * These labels preserve scanner evidence independently of the normalized level.
     * 
     * @return string
     */
    public function getSourceLevel() : string;

    /**
     * Returns original CVE identifiers.
     * 
     * Valid CVEs are normalized to uppercase; unavailable CVEs are empty strings.
     * Merged findings separate distinct CVEs with a semicolon and space.
     * 
     * @return string
     */
    public function getCve() : string;

    /**
     * Returns the public advisory identifier.
     * 
     * Unless explicitly supplied, the identifier prefers CVE over a linked GHSA
     * and then the native ID. EOL findings normally have no public identifier.
     * 
     * @return string
     */
    public function getPublicId() : string;

    /**
     * Returns the advisory description.
     * 
     * Unavailable descriptions are represented by an empty string.
     * 
     * @return string
     */
    public function getDescription() : string;

    /**
     * Returns remediation guidance.
     * 
     * This preserves scanner recommendations; unavailable guidance is an empty string.
     * 
     * @return string
     */
    public function getRemediation() : string;

    /**
     * Returns affected version ranges.
     * 
     * Ranges retain the scanner's notation rather than being parsed or rewritten.
     * 
     * @return string
     */
    public function getVersionsAffected() : string;

    /**
     * Returns installed versions.
     * 
     * Unavailable versions are represented by an empty string. Merged findings
     * separate distinct versions with a semicolon and space.
     * 
     * @return string
     */
    public function getVersionInstalled() : string;

    /**
     * Returns fixed versions or version ranges.
     * 
     * These values retain the scanner's notation; unavailable fixes are empty strings.
     * 
     * @return string
     */
    public function getVersionFixed() : string;

    /**
     * Compares all scalar evidence fields.
     * 
     * A shared public identifier alone does not make two findings equal;
     * package, scanner, severity and version evidence must also match.
     * 
     * @param FindingInterface $finding
     * @return bool
     */
   public function is(FindingInterface $finding) : bool;

}